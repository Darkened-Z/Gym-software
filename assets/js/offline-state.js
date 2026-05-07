(function () {
    const META_STORAGE_KEY = 'gym-offline-state-v1';
    const SNAPSHOT_STORAGE_KEY = 'gym-offline-snapshots-v1';
    const LEGACY_MEMBER_PROFILE_STORAGE_KEY = 'gym-member-profile-snapshots-v1';
    const STORAGE_VERSION = 1;
    const DEFAULT_RENEWAL_WINDOW_DAYS = 7;
    const DEFAULT_WARNING_LEAD_HOURS = 24;
    const DEFAULT_SNAPSHOT_TTL_MS = 7 * 24 * 60 * 60 * 1000;
    const DEFAULT_SNAPSHOT_LIMIT = 10;
    const DAY_MS = 24 * 60 * 60 * 1000;

    let metaMemory = null;
    let snapshotsMemory = null;
    let storageFallback = false;
    let legacyImported = false;

    function nowIso() {
        return new Date().toISOString();
    }

    function safeClone(value) {
        try {
            return JSON.parse(JSON.stringify(value));
        } catch (error) {
            return null;
        }
    }

    function parseJson(raw, fallback) {
        if (!raw) return fallback;
        try {
            return JSON.parse(raw);
        } catch (error) {
            return fallback;
        }
    }

    function createDefaultMeta() {
        return {
            version: STORAGE_VERSION,
            lastOnlineSuccess: null,
            lastOnlineCheck: null,
            renewalWindowDays: DEFAULT_RENEWAL_WINDOW_DAYS,
            warningLeadHours: DEFAULT_WARNING_LEAD_HOURS,
            modules: {}
        };
    }

    function createDefaultSnapshots() {
        return {
            version: STORAGE_VERSION,
            modules: {}
        };
    }

    function normalizeMeta(raw) {
        const base = createDefaultMeta();
        const source = raw && typeof raw === 'object' ? raw : {};
        const modules = source.modules && typeof source.modules === 'object' ? source.modules : {};
        const normalizedModules = {};

        Object.entries(modules).forEach(([moduleName, moduleState]) => {
            if (!moduleName) return;
            const state = moduleState && typeof moduleState === 'object' ? moduleState : {};
            normalizedModules[moduleName] = {
                lastOnlineSuccess: typeof state.lastOnlineSuccess === 'string' && state.lastOnlineSuccess ? state.lastOnlineSuccess : null,
                lastOfflineUse: typeof state.lastOfflineUse === 'string' && state.lastOfflineUse ? state.lastOfflineUse : null,
                lastStatusMessage: typeof state.lastStatusMessage === 'string' && state.lastStatusMessage ? state.lastStatusMessage : null,
                lastDetail: state.lastDetail && typeof state.lastDetail === 'object' ? safeClone(state.lastDetail) : null
            };
        });

        return {
            ...base,
            version: STORAGE_VERSION,
            lastOnlineSuccess: typeof source.lastOnlineSuccess === 'string' && source.lastOnlineSuccess ? source.lastOnlineSuccess : null,
            lastOnlineCheck: typeof source.lastOnlineCheck === 'string' && source.lastOnlineCheck ? source.lastOnlineCheck : null,
            renewalWindowDays: Number.isFinite(source.renewalWindowDays) && source.renewalWindowDays > 0 ? source.renewalWindowDays : DEFAULT_RENEWAL_WINDOW_DAYS,
            warningLeadHours: Number.isFinite(source.warningLeadHours) && source.warningLeadHours >= 0 ? source.warningLeadHours : DEFAULT_WARNING_LEAD_HOURS,
            modules: normalizedModules
        };
    }

    function normalizeSnapshotStore(raw) {
        const base = createDefaultSnapshots();
        const source = raw && typeof raw === 'object' ? raw : {};
        const modules = source.modules && typeof source.modules === 'object' ? source.modules : {};
        const normalizedModules = {};

        Object.entries(modules).forEach(([moduleName, moduleState]) => {
            if (!moduleName) return;
            const state = moduleState && typeof moduleState === 'object' ? moduleState : {};
            const items = Array.isArray(state.items) ? state.items : [];
            const limit = Number.isFinite(state.limit) && state.limit > 0 ? state.limit : DEFAULT_SNAPSHOT_LIMIT;
            const ttlMs = Number.isFinite(state.ttlMs) && state.ttlMs > 0 ? state.ttlMs : DEFAULT_SNAPSHOT_TTL_MS;
            normalizedModules[moduleName] = {
                limit,
                ttlMs,
                items: items
                    .map(item => normalizeSnapshotItem(moduleName, item, { ttlMs, limit }))
                    .filter(Boolean)
            };
            pruneSnapshotModule(normalizedModules[moduleName]);
        });

        return {
            ...base,
            version: STORAGE_VERSION,
            modules: normalizedModules
        };
    }

    function normalizeSnapshotItem(moduleName, item, options = {}) {
        if (!item || typeof item !== 'object') return null;

        const key = typeof item.key === 'string' && item.key.trim()
            ? item.key.trim()
            : typeof item.snapshotKey === 'string' && item.snapshotKey.trim()
                ? item.snapshotKey.trim()
                : typeof item.member_code === 'string' && item.member_code.trim()
                    ? item.member_code.trim()
                    : '';

        if (!key) return null;

        const cachedAt = typeof item.cachedAt === 'string' && item.cachedAt
            ? item.cachedAt
            : typeof item.cached_at === 'string' && item.cached_at
                ? item.cached_at
                : nowIso();
        const lastUsedAt = typeof item.lastUsedAt === 'string' && item.lastUsedAt
            ? item.lastUsedAt
            : typeof item.last_used_at === 'string' && item.last_used_at
                ? item.last_used_at
                : cachedAt;
        const ttlMs = Number.isFinite(item.ttlMs) && item.ttlMs > 0
            ? item.ttlMs
            : Number.isFinite(options.ttlMs) && options.ttlMs > 0
                ? options.ttlMs
                : DEFAULT_SNAPSHOT_TTL_MS;
        const expiresAt = typeof item.expiresAt === 'string' && item.expiresAt
            ? item.expiresAt
            : new Date(new Date(cachedAt).getTime() + ttlMs).toISOString();
        const payload = Object.prototype.hasOwnProperty.call(item, 'payload')
            ? safeClone(item.payload)
            : Object.prototype.hasOwnProperty.call(item, 'snapshot')
                ? safeClone(item.snapshot)
                : Object.prototype.hasOwnProperty.call(item, 'data')
                    ? safeClone(item.data)
                    : safeClone(item);

        return {
            module: moduleName,
            key,
            payload,
            source: typeof item.source === 'string' && item.source ? item.source : 'live',
            cachedAt,
            lastUsedAt,
            ttlMs,
            expiresAt
        };
    }

    function pruneSnapshotModule(moduleState) {
        if (!moduleState || typeof moduleState !== 'object') return;

        const now = Date.now();
        const seen = new Set();
        const limit = Number.isFinite(moduleState.limit) && moduleState.limit > 0 ? moduleState.limit : DEFAULT_SNAPSHOT_LIMIT;
        const ttlMs = Number.isFinite(moduleState.ttlMs) && moduleState.ttlMs > 0 ? moduleState.ttlMs : DEFAULT_SNAPSHOT_TTL_MS;
        const pruned = [];

        moduleState.items.forEach(item => {
            const normalized = normalizeSnapshotItem(item.module || 'unknown', item, { ttlMs, limit });
            if (!normalized) return;
            const cachedAt = new Date(normalized.cachedAt).getTime();
            const expiresAt = new Date(normalized.expiresAt).getTime();
            if (!Number.isFinite(cachedAt) || !Number.isFinite(expiresAt)) return;
            if (expiresAt <= now) return;
            const key = String(normalized.key).toLowerCase();
            if (seen.has(key)) return;
            seen.add(key);
            pruned.push(normalized);
        });

        pruned.sort((a, b) => new Date(b.lastUsedAt).getTime() - new Date(a.lastUsedAt).getTime());
        moduleState.items = pruned.slice(0, limit);
    }

    function readMeta() {
        if (storageFallback && metaMemory) {
            return safeClone(metaMemory) || createDefaultMeta();
        }

        try {
            const raw = window.localStorage.getItem(META_STORAGE_KEY);
            const parsed = normalizeMeta(parseJson(raw, createDefaultMeta()));
            metaMemory = parsed;
            return safeClone(parsed) || createDefaultMeta();
        } catch (error) {
            storageFallback = true;
            metaMemory = normalizeMeta(metaMemory || createDefaultMeta());
            return safeClone(metaMemory) || createDefaultMeta();
        }
    }

    function writeMeta(nextMeta) {
        const normalized = normalizeMeta(nextMeta);
        metaMemory = normalized;

        try {
            window.localStorage.setItem(META_STORAGE_KEY, JSON.stringify(normalized));
            storageFallback = false;
        } catch (error) {
            storageFallback = true;
        }

        return safeClone(normalized) || createDefaultMeta();
    }

    function readSnapshots() {
        if (storageFallback && snapshotsMemory) {
            return safeClone(snapshotsMemory) || createDefaultSnapshots();
        }

        try {
            const raw = window.localStorage.getItem(SNAPSHOT_STORAGE_KEY);
            const parsed = normalizeSnapshotStore(parseJson(raw, createDefaultSnapshots()));
            snapshotsMemory = parsed;
            return safeClone(parsed) || createDefaultSnapshots();
        } catch (error) {
            storageFallback = true;
            snapshotsMemory = normalizeSnapshotStore(snapshotsMemory || createDefaultSnapshots());
            return safeClone(snapshotsMemory) || createDefaultSnapshots();
        }
    }

    function writeSnapshots(nextSnapshots) {
        const normalized = normalizeSnapshotStore(nextSnapshots);
        snapshotsMemory = normalized;

        try {
            window.localStorage.setItem(SNAPSHOT_STORAGE_KEY, JSON.stringify(normalized));
            storageFallback = false;
        } catch (error) {
            storageFallback = true;
        }

        return safeClone(normalized) || createDefaultSnapshots();
    }

    function getRenewalStatus() {
        const meta = readMeta();
        const lastOnlineSuccess = meta.lastOnlineSuccess;
        const warningLeadMs = meta.warningLeadHours * 60 * 60 * 1000;
        const windowMs = meta.renewalWindowDays * DAY_MS;

        if (!lastOnlineSuccess) {
            return {
                status: 'unknown',
                online: navigator.onLine !== false,
                windowDays: meta.renewalWindowDays,
                warningLeadHours: meta.warningLeadHours,
                ageMs: null,
                remainingMs: null,
                remainingHours: null,
                remainingDays: null,
                expiresAt: null,
                canUseFullOffline: false,
                label: 'Offline renewal not established',
                message: 'This browser has not recorded a successful online session yet.'
            };
        }

        const lastStamp = new Date(lastOnlineSuccess).getTime();
        if (!Number.isFinite(lastStamp)) {
            return {
                status: 'unknown',
                online: navigator.onLine !== false,
                windowDays: meta.renewalWindowDays,
                warningLeadHours: meta.warningLeadHours,
                ageMs: null,
                remainingMs: null,
                remainingHours: null,
                remainingDays: null,
                expiresAt: null,
                canUseFullOffline: false,
                label: 'Offline renewal not established',
                message: 'The last successful online timestamp is invalid.'
            };
        }

        const ageMs = Date.now() - lastStamp;
        const remainingMs = windowMs - ageMs;
        const remainingHours = remainingMs / (60 * 60 * 1000);
        const remainingDays = remainingMs / DAY_MS;
        const expiresAt = new Date(lastStamp + windowMs).toISOString();
        const remainingText = formatDuration(Math.max(0, remainingMs));

        if (remainingMs <= 0) {
            const overdueText = formatDuration(Math.abs(remainingMs));
            return {
                status: 'expired',
                online: navigator.onLine !== false,
                windowDays: meta.renewalWindowDays,
                warningLeadHours: meta.warningLeadHours,
                ageMs,
                remainingMs,
                remainingHours,
                remainingDays,
                expiresAt,
                canUseFullOffline: false,
                label: 'Online renewal overdue',
                message: `Reconnect now. The 7-day offline window expired ${overdueText} ago.`
            };
        }

        if (remainingMs <= warningLeadMs) {
            return {
                status: 'warning',
                online: navigator.onLine !== false,
                windowDays: meta.renewalWindowDays,
                warningLeadHours: meta.warningLeadHours,
                ageMs,
                remainingMs,
                remainingHours,
                remainingDays,
                expiresAt,
                canUseFullOffline: true,
                label: 'Online renewal due soon',
                message: `Reconnect within ${remainingText} to keep the full offline mode trusted.`
            };
        }

        return {
            status: 'fresh',
            online: navigator.onLine !== false,
            windowDays: meta.renewalWindowDays,
            warningLeadHours: meta.warningLeadHours,
            ageMs,
            remainingMs,
            remainingHours,
            remainingDays,
            expiresAt,
            canUseFullOffline: true,
            label: 'Offline renewal healthy',
            message: `Next online renewal is due in ${remainingText}.`
        };
    }

    function getCapabilityStatus(moduleName = 'global') {
        const renewal = getRenewalStatus();
        const online = navigator.onLine !== false;
        const moduleState = readMeta().modules[moduleName] || {};
        const hasSnapshotSupport = moduleName === 'member-profile' || moduleName === 'attendance';
        const hasQueueSupport = moduleName === 'attendance';
        const mode = online
            ? 'online'
            : renewal.status === 'expired'
                ? 'offline-expired'
                : hasSnapshotSupport
                    ? 'offline-ready'
                    : 'offline-limited';
        const message = online
            ? renewal.status === 'warning'
                ? `Online. ${renewal.message}`
                : 'Online and fully connected.'
            : renewal.status === 'expired'
                ? `Offline fallback is limited until this browser reconnects. ${renewal.message}`
                : hasSnapshotSupport
                    ? `Cached data is available for this module. ${renewal.message}`
                    : `This module still needs the server. ${renewal.message}`;

        return {
            module: moduleName,
            online,
            mode,
            canQueueWrites: hasQueueSupport,
            canUseCachedSnapshots: hasSnapshotSupport,
            renewal,
            lastOnlineSuccess: moduleState.lastOnlineSuccess || readMeta().lastOnlineSuccess,
            message
        };
    }

    function formatDuration(ms) {
        const safeMs = Math.max(0, Math.floor(ms || 0));
        const totalMinutes = Math.floor(safeMs / 60000);
        if (totalMinutes < 1) return 'less than 1 minute';

        const days = Math.floor(totalMinutes / (60 * 24));
        const hours = Math.floor((totalMinutes % (60 * 24)) / 60);
        const minutes = totalMinutes % 60;
        const parts = [];
        if (days) parts.push(`${days} day${days === 1 ? '' : 's'}`);
        if (hours) parts.push(`${hours} hour${hours === 1 ? '' : 's'}`);
        if (!days && minutes) parts.push(`${minutes} min${minutes === 1 ? '' : 's'}`);
        if (!parts.length) return 'less than 1 minute';
        return parts.length > 2 ? parts.slice(0, 2).join(' ') : parts.join(' ');
    }

    function renderCapabilityNotice(moduleName, options = {}) {
        const status = getCapabilityStatus(moduleName);
        const title = options.title || status.label;
        const body = options.body || status.message;
        const tone = status.renewal.status === 'expired'
            ? {
                background: '#fef2f2',
                border: '#fca5a5',
                title: '#b91c1c',
                body: '#7f1d1d'
            }
            : status.renewal.status === 'warning'
                ? {
                    background: '#fffbeb',
                    border: '#fcd34d',
                    title: '#b45309',
                    body: '#78350f'
                }
                : status.online
                    ? {
                        background: '#ecfdf5',
                        border: '#86efac',
                        title: '#166534',
                        body: '#14532d'
                    }
                    : {
                        background: '#f8fafc',
                        border: '#cbd5e1',
                        title: '#334155',
                        body: '#475569'
                    };

        return `
            <div style="margin: 0.85rem 0; padding: 0.9rem 1rem; border: 1px solid ${tone.border}; background: ${tone.background}; border-radius: 12px;">
                <div style="font-weight: 700; color: ${tone.title};">${title}</div>
                <div style="margin-top: 0.35rem; color: ${tone.body}; line-height: 1.4;">${body}</div>
            </div>
        `;
    }

    function moduleSnapshotState(moduleName, options = {}) {
        const store = readSnapshots();
        if (!store.modules[moduleName]) {
            store.modules[moduleName] = {
                limit: Number.isFinite(options.limit) && options.limit > 0 ? options.limit : DEFAULT_SNAPSHOT_LIMIT,
                ttlMs: Number.isFinite(options.ttlMs) && options.ttlMs > 0 ? options.ttlMs : DEFAULT_SNAPSHOT_TTL_MS,
                items: []
            };
        }

        const moduleState = store.modules[moduleName];
        if (Number.isFinite(options.limit) && options.limit > 0) {
            moduleState.limit = options.limit;
        }
        if (Number.isFinite(options.ttlMs) && options.ttlMs > 0) {
            moduleState.ttlMs = options.ttlMs;
        }

        pruneSnapshotModule(moduleState);
        return { store, moduleState };
    }

    function storeSnapshot(moduleName, snapshotKey, payload, options = {}) {
        if (!moduleName || !snapshotKey) return null;
        const { store, moduleState } = moduleSnapshotState(moduleName, options);
        const normalizedKey = String(snapshotKey).trim();
        const now = nowIso();
        const ttlMs = Number.isFinite(options.ttlMs) && options.ttlMs > 0 ? options.ttlMs : moduleState.ttlMs;
        const record = normalizeSnapshotItem(moduleName, {
            key: normalizedKey,
            payload: safeClone(payload),
            source: typeof options.source === 'string' && options.source ? options.source : 'live',
            cachedAt: now,
            lastUsedAt: now,
            ttlMs
        }, { ttlMs, limit: moduleState.limit });

        if (!record) return null;

        moduleState.items = moduleState.items.filter(item => String(item.key).toLowerCase() !== normalizedKey.toLowerCase());
        moduleState.items.unshift(record);
        pruneSnapshotModule(moduleState);
        writeSnapshots(store);
        return safeClone(record) || record;
    }

    function getSnapshot(moduleName, snapshotKey) {
        if (!moduleName || !snapshotKey) return null;
        const store = readSnapshots();
        const moduleState = store.modules[moduleName];
        if (!moduleState || !Array.isArray(moduleState.items)) return null;

        const normalizedKey = String(snapshotKey).trim().toLowerCase();
        const now = Date.now();
        let changed = false;
        let found = null;
        const remaining = [];

        moduleState.items.forEach(item => {
            const normalized = normalizeSnapshotItem(moduleName, item, { ttlMs: moduleState.ttlMs, limit: moduleState.limit });
            if (!normalized) {
                changed = true;
                return;
            }
            const expiresAt = new Date(normalized.expiresAt).getTime();
            if (!Number.isFinite(expiresAt) || expiresAt <= now) {
                changed = true;
                return;
            }
            if (!found && String(normalized.key).toLowerCase() === normalizedKey) {
                found = {
                    ...normalized,
                    lastUsedAt: nowIso()
                };
                remaining.push(found);
                changed = true;
                return;
            }
            remaining.push(normalized);
        });

        if (!found) {
            if (changed) {
                moduleState.items = remaining;
                pruneSnapshotModule(moduleState);
                writeSnapshots(store);
            }
            return null;
        }

        moduleState.items = remaining;
        pruneSnapshotModule(moduleState);
        writeSnapshots(store);
        return safeClone(found) || found;
    }

    function listSnapshots(moduleName) {
        const store = readSnapshots();
        const moduleState = store.modules[moduleName];
        if (!moduleState || !Array.isArray(moduleState.items)) return [];
        pruneSnapshotModule(moduleState);
        writeSnapshots(store);
        return moduleState.items.map(item => safeClone(item) || item);
    }

    function clearSnapshots(moduleName) {
        const store = readSnapshots();
        if (moduleName) {
            delete store.modules[moduleName];
        } else {
            store.modules = {};
        }
        writeSnapshots(store);
    }

    function recordOnlineSuccess(moduleName = 'global', detail = {}) {
        const meta = readMeta();
        const stamp = nowIso();
        meta.lastOnlineSuccess = stamp;
        meta.lastOnlineCheck = stamp;
        meta.modules[moduleName] = {
            ...(meta.modules[moduleName] || {}),
            lastOnlineSuccess: stamp,
            lastStatusMessage: typeof detail.message === 'string' && detail.message ? detail.message : null,
            lastDetail: detail && typeof detail === 'object' ? safeClone(detail) : null
        };
        return writeMeta(meta);
    }

    function recordOfflineUse(moduleName = 'global', detail = {}) {
        const meta = readMeta();
        const stamp = nowIso();
        meta.lastOnlineCheck = stamp;
        meta.modules[moduleName] = {
            ...(meta.modules[moduleName] || {}),
            lastOfflineUse: stamp,
            lastStatusMessage: typeof detail.message === 'string' && detail.message ? detail.message : null,
            lastDetail: detail && typeof detail === 'object' ? safeClone(detail) : null
        };
        return writeMeta(meta);
    }

    function importLegacyMemberProfileSnapshots() {
        if (legacyImported) return;
        legacyImported = true;

        try {
            const current = readSnapshots();
            const existing = current.modules['member-profile'];
            if (existing && Array.isArray(existing.items) && existing.items.length) {
                return;
            }

            const raw = window.localStorage.getItem(LEGACY_MEMBER_PROFILE_STORAGE_KEY);
            if (!raw) return;
            const parsed = parseJson(raw, []);
            if (!Array.isArray(parsed) || !parsed.length) return;

            parsed.forEach(entry => {
                const normalized = normalizeSnapshotItem('member-profile', {
                    key: `${entry?.gender || 'men'}:${entry?.member_code || ''}`,
                    payload: entry,
                    source: typeof entry?.source === 'string' && entry.source ? entry.source : 'legacy',
                    cachedAt: typeof entry?.cached_at === 'string' && entry.cached_at ? entry.cached_at : nowIso(),
                    lastUsedAt: typeof entry?.last_used_at === 'string' && entry.last_used_at ? entry.last_used_at : nowIso(),
                    ttlMs: DEFAULT_SNAPSHOT_TTL_MS
                }, { ttlMs: DEFAULT_SNAPSHOT_TTL_MS, limit: DEFAULT_SNAPSHOT_LIMIT });
                if (!normalized) return;
                if (!current.modules['member-profile']) {
                    current.modules['member-profile'] = {
                        limit: DEFAULT_SNAPSHOT_LIMIT,
                        ttlMs: DEFAULT_SNAPSHOT_TTL_MS,
                        items: []
                    };
                }
                current.modules['member-profile'].items.push(normalized);
            });

            pruneSnapshotModule(current.modules['member-profile']);
            writeSnapshots(current);
            window.localStorage.removeItem(LEGACY_MEMBER_PROFILE_STORAGE_KEY);
        } catch (error) {
            // Ignore legacy import failures; the live store will still work.
        }
    }

    importLegacyMemberProfileSnapshots();

    window.OfflineState = window.OfflineState || {
        getMeta: readMeta,
        setMeta: writeMeta,
        recordOnlineSuccess,
        recordOfflineUse,
        getRenewalStatus,
        getCapabilityStatus,
        renderCapabilityNotice,
        storeSnapshot,
        getSnapshot,
        listSnapshots,
        clearSnapshots,
        formatDuration,
        importLegacyMemberProfileSnapshots
    };
})();
