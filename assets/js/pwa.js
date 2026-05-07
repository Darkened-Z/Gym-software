(function () {
    const SW_PATH = '/sw.js';
    const ATTENDANCE_OUTBOX_STORAGE_KEY = 'gym-attendance-outbox-v1';
    const ATTENDANCE_OUTBOX_PANEL_SELECTOR = '[data-attendance-outbox-panel]';

    let flushInFlight = null;
    let memoryQueue = [];
    let storageFallback = false;

    function safeClone(value) {
        try {
            return JSON.parse(JSON.stringify(value));
        } catch (error) {
            return null;
        }
    }

    function getOfflineState() {
        return window.OfflineState && typeof window.OfflineState.getCapabilityStatus === 'function' ? window.OfflineState : null;
    }

    function noteOnlineSuccess(moduleName, detail = {}) {
        const offlineState = getOfflineState();
        if (!offlineState || typeof offlineState.recordOnlineSuccess !== 'function') return;
        offlineState.recordOnlineSuccess(moduleName, detail);
    }

    function noteOfflineUse(moduleName, detail = {}) {
        const offlineState = getOfflineState();
        if (!offlineState || typeof offlineState.recordOfflineUse !== 'function') return;
        offlineState.recordOfflineUse(moduleName, detail);
    }

    function getRenewalStatus() {
        const offlineState = getOfflineState();
        return offlineState ? offlineState.getRenewalStatus() : null;
    }

    function makeId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        return `${Date.now()}-${Math.random().toString(16).slice(2)}`;
    }

    function readStoredQueue() {
        if (storageFallback) {
            return memoryQueue.slice();
        }

        try {
            const raw = window.localStorage.getItem(ATTENDANCE_OUTBOX_STORAGE_KEY);
            if (!raw) return [];
            const parsed = JSON.parse(raw);
            if (!Array.isArray(parsed)) return [];
            return parsed.map(normalizeQueueItem).filter(Boolean);
        } catch (error) {
            storageFallback = true;
            return memoryQueue.slice();
        }
    }

    function writeStoredQueue(queue) {
        const normalizedQueue = queue.map(normalizeQueueItem).filter(Boolean);
        memoryQueue = normalizedQueue.slice();

        try {
            window.localStorage.setItem(ATTENDANCE_OUTBOX_STORAGE_KEY, JSON.stringify(normalizedQueue));
            storageFallback = false;
        } catch (error) {
            storageFallback = true;
        }

        updateStatusBar();
        refreshAttendanceOutboxPanels();
        window.dispatchEvent(new CustomEvent('attendance-outbox:changed', {
            detail: getQueueSummary()
        }));
    }

    function normalizeQueueItem(item) {
        if (!item || typeof item !== 'object') return null;

        const action = item.action === 'checkout' ? 'checkout' : 'checkin';
        const payload = item.payload && typeof item.payload === 'object' ? safeClone(item.payload) || {} : {};
        return {
            id: typeof item.id === 'string' && item.id ? item.id : makeId(),
            action,
            payload,
            createdAt: typeof item.createdAt === 'string' && item.createdAt ? item.createdAt : new Date().toISOString(),
            attempts: Number.isFinite(item.attempts) ? item.attempts : 0,
            lastError: typeof item.lastError === 'string' ? item.lastError : null,
            dedupeKey: typeof item.dedupeKey === 'string' && item.dedupeKey ? item.dedupeKey : buildDedupeKey(action, payload),
            source: typeof item.source === 'string' && item.source ? item.source : 'attendance-outbox'
        };
    }

    function getQueue() {
        return readStoredQueue();
    }

    function getPendingCount() {
        return getQueue().length;
    }

    function getQueueSummary() {
        const queue = getQueue();
        const checkins = queue.filter(item => item.action === 'checkin');
        const checkouts = queue.filter(item => item.action === 'checkout');
        return {
            pendingCount: queue.length,
            pendingCheckins: checkins.length,
            pendingCheckouts: checkouts.length,
            items: queue,
            persistenceMode: storageFallback ? 'session' : 'localStorage',
            online: navigator.onLine !== false
        };
    }

    function buildDedupeKey(action, payload) {
        const memberId = payload && payload.member_id ? String(payload.member_id) : '';
        const attendanceId = payload && payload.attendance_id ? String(payload.attendance_id) : '';
        const gender = payload && payload.gender ? String(payload.gender) : '';
        return [action, memberId, attendanceId, gender].join('|');
    }

    function formatQueuedAge(createdAt) {
        const timestamp = new Date(createdAt).getTime();
        if (!Number.isFinite(timestamp)) return 'just now';

        const minutes = Math.max(0, Math.floor((Date.now() - timestamp) / 60000));
        if (minutes <= 0) return 'just now';
        if (minutes === 1) return '1 min ago';
        if (minutes < 60) return `${minutes} mins ago`;

        const hours = Math.floor(minutes / 60);
        if (hours === 1) return '1 hour ago';
        return `${hours} hours ago`;
    }

    function describeQueueItem(item) {
        const actionLabel = item.action === 'checkout' ? 'Check-out' : 'Check-in';
        return `${actionLabel} • ${formatQueuedAge(item.createdAt)}`;
    }

    function buildPanelHtml(summary) {
        const hasPending = summary.pendingCount > 0;
        const renewal = getRenewalStatus();
        const queueNote = summary.persistenceMode === 'session'
            ? 'This browser can only hold the queue for this session.'
            : 'Queued on this device until it can replay.';
        const renewalNote = renewal && renewal.status !== 'fresh'
            ? `<p style="margin:0.4rem 0 0;color:${renewal.status === 'expired' ? '#7f1d1d' : '#78350f'};">${renewal.message}</p>`
            : '';
        const replayLabel = summary.online ? 'Replay now' : 'Replay when online';
        const replayDisabled = summary.online ? '' : 'disabled';
        const replayStyle = summary.online
            ? 'padding:0.7rem 1rem;border:none;border-radius:8px;background:#166534;color:#fff;cursor:pointer;font-weight:700;'
            : 'padding:0.7rem 1rem;border:none;border-radius:8px;background:#9ca3af;color:#fff;cursor:not-allowed;font-weight:700;';

        if (!hasPending) {
            return `
                <div style="margin:1rem 0;padding:1rem 1.25rem;border:1px solid rgba(22,101,52,0.15);border-radius:12px;background:#f8fafc;">
                    <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:center;">
                        <div>
                            <div style="font-size:0.85rem;text-transform:uppercase;letter-spacing:0.08em;color:#166534;font-weight:700;">Attendance outbox</div>
                            <h3 style="margin:0.35rem 0 0;">No pending attendance actions</h3>
                            <p style="margin:0.5rem 0 0;color:#475569;">Attendance writes are not waiting on this device right now.</p>
                        </div>
                        <span style="padding:0.35rem 0.7rem;border-radius:999px;background:#dcfce7;color:#166534;font-weight:700;">0 pending</span>
                    </div>
                </div>
            `;
        }

        const items = summary.items.slice(0, 3).map(item => `
            <li style="display:flex;justify-content:space-between;gap:1rem;padding:0.45rem 0;border-top:1px solid rgba(148,163,184,0.18);">
                <span>${describeQueueItem(item)}</span>
                <span style="color:#64748b;">${item.source === 'attendance-outbox' ? '' : item.source}</span>
            </li>
        `).join('');

        return `
            <div style="margin:1rem 0;padding:1rem 1.25rem;border:1px solid rgba(245,158,11,0.35);border-radius:12px;background:#fffbeb;">
                <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:flex-start;">
                    <div>
                        <div style="font-size:0.85rem;text-transform:uppercase;letter-spacing:0.08em;color:#b45309;font-weight:700;">Attendance outbox</div>
                        <h3 style="margin:0.35rem 0 0;">${summary.pendingCount} queued attendance action${summary.pendingCount === 1 ? '' : 's'}</h3>
                        <p style="margin:0.5rem 0 0;color:#92400e;">Queued attendance writes stay local and replay automatically when the connection returns.</p>
                        <p style="margin:0.4rem 0 0;color:#7c2d12;">${queueNote}</p>
                        ${renewalNote}
                    </div>
                    <div style="display:flex;flex-direction:column;align-items:flex-end;gap:0.5rem;">
                        <span style="padding:0.35rem 0.7rem;border-radius:999px;background:#fef3c7;color:#92400e;font-weight:700;">${summary.pendingCount} pending</span>
                        <button type="button" onclick="window.AttendanceOutbox && window.AttendanceOutbox.flushPending()" ${replayDisabled} style="${replayStyle}">${replayLabel}</button>
                    </div>
                </div>
                <div style="margin-top:0.85rem;background:rgba(255,255,255,0.65);border-radius:10px;padding:0.75rem 0.9rem;">
                    <div style="font-weight:700;color:#78350f;margin-bottom:0.45rem;">Queued items</div>
                    <ul style="list-style:none;padding:0;margin:0;">
                        ${items}
                    </ul>
                </div>
            </div>
        `;
    }

    function refreshAttendanceOutboxPanels() {
        const panels = document.querySelectorAll(ATTENDANCE_OUTBOX_PANEL_SELECTOR);
        if (!panels.length) return;

        const summary = getQueueSummary();
        const html = buildPanelHtml(summary);
        panels.forEach(panel => {
            panel.innerHTML = html;
        });
    }

    function updateStatusBar() {
        let bar = document.getElementById('pwa-status-bar');
        if (!bar) {
            bar = ensureStatusBar();
        }

        const config = getStatusConfig();
        const summary = getQueueSummary();
        const titleEl = bar.querySelector('.pwa-status-bar__title');
        const detailEl = bar.querySelector('.pwa-status-bar__detail');
        const badgeEl = bar.querySelector('.pwa-status-bar__badge');

        bar.classList.remove('is-online', 'is-offline');
        bar.classList.add(config.className);
        titleEl.textContent = config.title;

        let detail = config.detail;
        if (summary.pendingCount > 0) {
            detail += summary.online
                ? ` ${summary.pendingCount} attendance action${summary.pendingCount === 1 ? '' : 's'} waiting to replay.`
                : ` ${summary.pendingCount} attendance action${summary.pendingCount === 1 ? '' : 's'} queued locally.`;
        }
        detailEl.textContent = detail;

        badgeEl.textContent = summary.pendingCount > 0
            ? String(summary.pendingCount)
            : (config.className === 'is-online' ? '✓' : '•');

        document.documentElement.dataset.networkStatus = config.className === 'is-online' ? 'online' : 'offline';
        if (document.body) {
            document.body.classList.toggle('pwa-offline', config.className === 'is-offline');
        }
    }

    function getStatusConfig() {
        const online = navigator.onLine !== false;
        const renewal = getRenewalStatus();
        const renewalDetail = renewal && renewal.status !== 'fresh' ? ` ${renewal.message}` : '';
        return online
            ? {
                className: 'is-online',
                title: 'Online',
                detail: `Live data and sign-in are available.${renewalDetail}`
            }
            : {
                className: 'is-offline',
                title: 'Offline',
                detail: `Shell is available. Live data and sign-in need a connection.${renewalDetail}`
            };
    }

    function ensureStatusBar() {
        let bar = document.getElementById('pwa-status-bar');
        if (bar) return bar;

        bar = document.createElement('div');
        bar.id = 'pwa-status-bar';
        bar.className = 'pwa-status-bar';
        bar.setAttribute('role', 'status');
        bar.setAttribute('aria-live', 'polite');
        bar.innerHTML = `
            <div class="pwa-status-bar__message">
                <span class="pwa-status-bar__title"></span>
                <span class="pwa-status-bar__detail"></span>
            </div>
            <span class="pwa-status-bar__badge" aria-hidden="true"></span>
        `;

        const body = document.body || document.documentElement;
        if (body.firstChild) {
            body.insertBefore(bar, body.firstChild);
        } else {
            body.appendChild(bar);
        }
        return bar;
    }

    async function registerServiceWorker() {
        if (!('serviceWorker' in navigator)) return;
        if (!window.isSecureContext && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
            return;
        }

        try {
            await navigator.serviceWorker.register(SW_PATH, { scope: '/' });
        } catch (err) {
            console.warn('Service worker registration failed:', err);
        }
    }

    function isRetryableNetworkError(error) {
        const message = String(error && error.message ? error.message : error || '');
        return /failed to fetch|networkerror|network request failed/i.test(message) || error instanceof TypeError;
    }

    async function parseJsonResponse(response) {
        const text = await response.text();
        if (!text) return null;
        try {
            return JSON.parse(text);
        } catch (error) {
            return null;
        }
    }

    function queueAttendanceAction(action, payload, meta = {}) {
        const normalizedPayload = safeClone(payload) || {};
        const queue = getQueue();
        const dedupeKey = buildDedupeKey(action, normalizedPayload);
        const existing = queue.find(item => item.dedupeKey === dedupeKey);

        if (existing) {
            existing.lastError = null;
            writeStoredQueue(queue);
            window.dispatchEvent(new CustomEvent('attendance-outbox:queued', {
                detail: { item: existing, queued: false, count: queue.length }
            }));
            return { item: existing, queued: false, count: queue.length, deduped: true };
        }

        const item = normalizeQueueItem({
            id: makeId(),
            action,
            payload: normalizedPayload,
            createdAt: new Date().toISOString(),
            attempts: 0,
            lastError: null,
            dedupeKey,
            source: typeof meta.source === 'string' && meta.source ? meta.source : 'attendance-outbox'
        });

        queue.push(item);
        writeStoredQueue(queue);
        window.dispatchEvent(new CustomEvent('attendance-outbox:queued', {
            detail: { item, queued: true, count: queue.length }
        }));
        return { item, queued: true, count: queue.length, deduped: false };
    }

    async function submitAttendance(action, payload) {
        const endpoint = action === 'checkout'
            ? 'api/attendance-checkin.php?action=checkout'
            : 'api/attendance-checkin.php?action=checkin';
        const requestPayload = safeClone(payload) || {};

        if (!navigator.onLine) {
            const queued = queueAttendanceAction(action, requestPayload);
            noteOfflineUse('attendance', { action, source: 'submitAttendance' });
            return {
                success: true,
                queued: true,
                queuedCount: queued.count,
                message: 'Attendance action saved offline and will replay automatically.'
            };
        }

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(requestPayload)
            });

            const data = await parseJsonResponse(response);
            if (response.ok && data && data.success) {
                noteOnlineSuccess('attendance', { action, source: 'submitAttendance' });
                return {
                    success: true,
                    queued: false,
                    data,
                    message: data.message || 'Attendance recorded successfully.'
                };
            }

            return {
                success: false,
                queued: false,
                status: response.status,
                message: (data && data.message) || 'Failed to record attendance.'
            };
        } catch (error) {
            if (isRetryableNetworkError(error)) {
                const queued = queueAttendanceAction(action, requestPayload);
                return {
                    success: true,
                    queued: true,
                    queuedCount: queued.count,
                    message: 'Attendance action queued locally and will replay when the connection returns.'
                };
            }
            throw error;
        }
    }

    async function replayQueuedItem(item) {
        const endpoint = item.action === 'checkout'
            ? 'api/attendance-checkin.php?action=checkout'
            : 'api/attendance-checkin.php?action=checkin';

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(item.payload || {})
            });

            const data = await parseJsonResponse(response);
            if (response.ok && data && data.success) {
                noteOnlineSuccess('attendance', { action: item.action, source: 'flushPending' });
                return { completed: true, data };
            }

            if (!response.ok && response.status >= 500) {
                return {
                    transient: true,
                    error: new Error((data && data.message) || `Server error (${response.status})`)
                };
            }

            return {
                dropItem: true,
                error: new Error((data && data.message) || `Attendance replay failed (${response.status})`)
            };
        } catch (error) {
            return {
                transient: true,
                error
            };
        }
    }

    async function flushPending() {
        if (flushInFlight) return flushInFlight;

        flushInFlight = (async () => {
            if (!navigator.onLine) {
                refreshAttendanceOutboxPanels();
                updateStatusBar();
                noteOfflineUse('attendance', { source: 'flushPending' });
                return {
                    success: false,
                    replayed: 0,
                    remaining: getPendingCount(),
                    message: 'Still offline.'
                };
            }

            let replayed = 0;
            let dropped = 0;
            let lastError = null;
            window.dispatchEvent(new CustomEvent('attendance-outbox:flush-start', {
                detail: getQueueSummary()
            }));

            while (true) {
                const queue = getQueue();
                if (!queue.length) break;

                const item = queue[0];
                const result = await replayQueuedItem(item);

                if (result.completed) {
                    queue.shift();
                    replayed += 1;
                    writeStoredQueue(queue);
                    window.dispatchEvent(new CustomEvent('attendance-outbox:item-replayed', {
                        detail: { item, remaining: queue.length, replayed }
                    }));
                    continue;
                }

                if (result.dropItem) {
                    queue.shift();
                    dropped += 1;
                    lastError = result.error || null;
                    writeStoredQueue(queue);
                    window.dispatchEvent(new CustomEvent('attendance-outbox:item-dropped', {
                        detail: { item, remaining: queue.length, error: lastError ? lastError.message : null }
                    }));
                    continue;
                }

                lastError = result.error || null;
                break;
            }

            const summary = getQueueSummary();
            window.dispatchEvent(new CustomEvent('attendance-outbox:flush-end', {
                detail: {
                    replayed,
                    dropped,
                    remaining: summary.pendingCount,
                    error: lastError ? lastError.message : null
                }
            }));

            refreshAttendanceOutboxPanels();
            updateStatusBar();

            return {
                success: summary.pendingCount === 0 && !lastError,
                replayed,
                dropped,
                remaining: summary.pendingCount,
                error: lastError
            };
        })().finally(() => {
            flushInFlight = null;
        });

        return flushInFlight;
    }

    function init() {
        updateStatusBar();
        refreshAttendanceOutboxPanels();
        window.addEventListener('online', () => {
            updateStatusBar();
            flushPending();
        });
        window.addEventListener('offline', updateStatusBar);
        window.addEventListener('storage', event => {
            if (event.key === ATTENDANCE_OUTBOX_STORAGE_KEY) {
                refreshAttendanceOutboxPanels();
                updateStatusBar();
            }
        });
        window.addEventListener('attendance-outbox:changed', () => {
            refreshAttendanceOutboxPanels();
            updateStatusBar();
        });
        registerServiceWorker();
        if (navigator.onLine) {
            flushPending();
        }
    }

    window.GymPWA = window.GymPWA || {
        updateStatus: updateStatusBar,
        isOnline: function () {
            return navigator.onLine !== false;
        }
    };

    window.AttendanceOutbox = window.AttendanceOutbox || {
        submitCheckIn: function (payload) {
            return submitAttendance('checkin', payload);
        },
        submitCheckOut: function (payload) {
            return submitAttendance('checkout', payload);
        },
        flushPending: flushPending,
        refreshPanels: refreshAttendanceOutboxPanels,
        getPendingCount: getPendingCount,
        getQueueSummary: getQueueSummary
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})();
