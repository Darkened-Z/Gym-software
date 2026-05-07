/**
 * Member Profile JavaScript
 */

document.addEventListener('DOMContentLoaded', function () {
    const lookupBtn = document.getElementById('lookupBtn');
    const lookupInput = document.getElementById('memberCodeInput');

    if (lookupBtn) {
        lookupBtn.addEventListener('click', handleLookup);
    }

    if (lookupInput) {
        lookupInput.setAttribute('autocomplete', 'off');
        lookupInput.setAttribute('spellcheck', 'false');
        lookupInput.setAttribute('autocapitalize', 'characters');
        lookupInput.setAttribute('enterkeyhint', 'go');
        lookupInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                handleLookup();
            }
        });
    }

    window.addEventListener('attendance-outbox:flush-end', event => {
        const detail = event?.detail || {};
        if ((detail.replayed || 0) <= 0 && (detail.dropped || 0) <= 0) {
            return;
        }
        if (currentMemberData && currentMemberData.code) {
            loadMemberProfile(currentMemberData.code);
        }
    });

    // If a member code is provided in the URL, auto-load that profile (view-only, no auto check-in)
    try {
        const params = new URLSearchParams(window.location.search);
        const codeFromUrl = params.get('code');
        if (codeFromUrl) {
            if (lookupInput) {
                lookupInput.value = codeFromUrl;
            }
            loadMemberProfile(codeFromUrl);
        }
    } catch (e) {
        console.warn('Unable to parse URL params for member profile:', e);
    }
});

const MEMBER_PROFILE_SNAPSHOT_STORAGE_KEY = 'gym-member-profile-snapshots-v1';
const MEMBER_PROFILE_SNAPSHOT_LIMIT = 10;
const MEMBER_PROFILE_SNAPSHOT_TTL_MS = 7 * 24 * 60 * 60 * 1000;
const MEMBER_PROFILE_SNAPSHOT_STALE_MS = 24 * 60 * 60 * 1000;
const MEMBER_PROFILE_SNAPSHOT_MODULE = 'member-profile';
const MEMBER_PROFILE_PUBLIC_FIELDS = [
    'id',
    'member_code',
    'name',
    'membership_type',
    'join_date',
    'status',
    'calculated_status',
    'next_fee_due_date',
    'total_due_amount',
    'is_checked_in'
];

function getOfflineStateApi() {
    return window.OfflineState && typeof window.OfflineState.getCapabilityStatus === 'function' ? window.OfflineState : null;
}

function getRenewalStatus() {
    const offlineState = getOfflineStateApi();
    return offlineState ? offlineState.getRenewalStatus() : null;
}

function getLookupInput() {
    return document.getElementById('memberCodeInput');
}

function focusLookupInput(options = {}) {
    const input = getLookupInput();
    if (!input) return;

    const clear = Boolean(options.clear);
    const select = Boolean(options.select);

    requestAnimationFrame(() => {
        if (clear) {
            input.value = '';
        }
        input.focus();
        if (select && input.value) {
            input.select();
        }
    });
}

function parseMemberProfileSnapshotStore() {
    const offlineState = getOfflineStateApi();
    if (offlineState && typeof offlineState.listSnapshots === 'function') {
        return offlineState.listSnapshots(MEMBER_PROFILE_SNAPSHOT_MODULE).map(entry => entry.payload || entry.snapshot || entry).filter(Boolean);
    }

    try {
        const raw = window.localStorage.getItem(MEMBER_PROFILE_SNAPSHOT_STORAGE_KEY);
        if (!raw) return [];
        const parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? parsed : [];
    } catch (error) {
        return [];
    }
}

function writeMemberProfileSnapshotStore(entries) {
    const offlineState = getOfflineStateApi();
    if (offlineState && typeof offlineState.clearSnapshots === 'function' && typeof offlineState.storeSnapshot === 'function') {
        offlineState.clearSnapshots(MEMBER_PROFILE_SNAPSHOT_MODULE);
        entries.forEach(entry => {
            const normalized = normalizeMemberProfileSnapshot(entry);
            if (!normalized) return;
            const snapshotKey = `${normalized.gender}:${normalized.member_code.toLowerCase()}`;
            offlineState.storeSnapshot(MEMBER_PROFILE_SNAPSHOT_MODULE, snapshotKey, normalized, {
                ttlMs: MEMBER_PROFILE_SNAPSHOT_TTL_MS,
                limit: MEMBER_PROFILE_SNAPSHOT_LIMIT,
                source: normalized.source || 'live'
            });
        });
        try {
            window.localStorage.removeItem(MEMBER_PROFILE_SNAPSHOT_STORAGE_KEY);
        } catch (error) {
            // Ignore legacy cleanup failures.
        }
        return;
    }

    try {
        window.localStorage.setItem(MEMBER_PROFILE_SNAPSHOT_STORAGE_KEY, JSON.stringify(entries));
    } catch (error) {
        console.warn('Unable to persist member profile snapshot cache:', error);
    }
}

function clearMemberProfileSnapshotStore() {
    const offlineState = getOfflineStateApi();
    if (offlineState && typeof offlineState.clearSnapshots === 'function') {
        offlineState.clearSnapshots(MEMBER_PROFILE_SNAPSHOT_MODULE);
        try {
            window.localStorage.removeItem(MEMBER_PROFILE_SNAPSHOT_STORAGE_KEY);
        } catch (error) {
            // Ignore legacy cleanup failures.
        }
        return;
    }

    try {
        window.localStorage.removeItem(MEMBER_PROFILE_SNAPSHOT_STORAGE_KEY);
    } catch (error) {
        console.warn('Unable to clear member profile snapshot cache:', error);
    }
}

function normalizeMemberProfileSnapshot(entry) {
    if (!entry || typeof entry !== 'object') return null;
    const profile = entry.profile && typeof entry.profile === 'object' ? entry.profile : null;
    const memberCode = String(entry.member_code || profile?.member_code || '').trim();
    const gender = entry.gender === 'women' ? 'women' : 'men';
    const cachedAt = typeof entry.cached_at === 'string' && entry.cached_at ? entry.cached_at : new Date().toISOString();
    const renewalStatus = entry.renewal_status && typeof entry.renewal_status === 'object' ? {
        status: entry.renewal_status.status || 'unknown',
        online: Boolean(entry.renewal_status.online),
        windowDays: Number(entry.renewal_status.windowDays || 7),
        warningLeadHours: Number(entry.renewal_status.warningLeadHours || 24),
        expiresAt: entry.renewal_status.expiresAt || null,
        label: entry.renewal_status.label || 'Offline renewal status',
        message: entry.renewal_status.message || null,
        canUseFullOffline: Boolean(entry.renewal_status.canUseFullOffline)
    } : null;

    if (!memberCode || !profile || !profile.id) return null;

    return {
        member_code: memberCode,
        gender,
        cached_at: cachedAt,
        last_used_at: typeof entry.last_used_at === 'string' && entry.last_used_at ? entry.last_used_at : cachedAt,
        source: typeof entry.source === 'string' && entry.source ? entry.source : 'live',
        profile: redactMemberProfileForOffline(profile),
        is_defaulter: Boolean(entry.is_defaulter),
        default_date: entry.default_date || profile.next_fee_due_date || null,
        read_only: true,
        renewal_status: renewalStatus
    };
}

function pruneMemberProfileSnapshots(entries) {
    const cutoff = Date.now() - MEMBER_PROFILE_SNAPSHOT_TTL_MS;
    const seen = new Set();
    const pruned = [];

    entries.forEach(entry => {
        const normalized = normalizeMemberProfileSnapshot(entry);
        if (!normalized) return;
        const cachedAt = new Date(normalized.cached_at).getTime();
        if (!Number.isFinite(cachedAt) || cachedAt < cutoff) return;
        const key = `${normalized.gender}:${normalized.member_code.toLowerCase()}`;
        if (seen.has(key)) return;
        seen.add(key);
        pruned.push(normalized);
    });

    pruned.sort((a, b) => new Date(b.last_used_at).getTime() - new Date(a.last_used_at).getTime());
    return pruned.slice(0, MEMBER_PROFILE_SNAPSHOT_LIMIT);
}

function getMemberProfileSnapshots() {
    const entries = parseMemberProfileSnapshotStore();
    const pruned = pruneMemberProfileSnapshots(entries);
    if (pruned.length !== entries.length) {
        writeMemberProfileSnapshotStore(pruned);
    }
    return pruned;
}

function persistMemberProfileSnapshot(data, source = 'live') {
    const member = data?.profile || data?.data || null;
    if (!member || !member.id || !member.member_code) return null;
    const renewalStatus = getRenewalStatus();

    const snapshot = normalizeMemberProfileSnapshot({
        member_code: member.member_code,
        gender: data.gender,
        cached_at: new Date().toISOString(),
        last_used_at: new Date().toISOString(),
        source,
        profile: redactMemberProfileForOffline(member),
        is_defaulter: data.is_defaulter,
        default_date: data.default_date,
        renewal_status: renewalStatus
    });

    if (!snapshot) return null;

    const offlineState = getOfflineStateApi();
    if (offlineState && typeof offlineState.storeSnapshot === 'function') {
        offlineState.storeSnapshot(MEMBER_PROFILE_SNAPSHOT_MODULE, `${snapshot.gender}:${snapshot.member_code.toLowerCase()}`, snapshot, {
            ttlMs: MEMBER_PROFILE_SNAPSHOT_TTL_MS,
            limit: MEMBER_PROFILE_SNAPSHOT_LIMIT,
            source
        });
    } else {
        const snapshots = getMemberProfileSnapshots().filter(entry => `${entry.gender}:${entry.member_code.toLowerCase()}` !== `${snapshot.gender}:${snapshot.member_code.toLowerCase()}`);
        snapshots.unshift(snapshot);
        writeMemberProfileSnapshotStore(pruneMemberProfileSnapshots(snapshots));
    }
    return snapshot;
}

function getCachedMemberProfile(memberCode) {
    const normalizedCode = String(memberCode || '').trim().toLowerCase();
    if (!normalizedCode) return null;

    const offlineState = getOfflineStateApi();
    if (offlineState && typeof offlineState.getSnapshot === 'function') {
        const menSnapshot = offlineState.getSnapshot(MEMBER_PROFILE_SNAPSHOT_MODULE, `men:${normalizedCode}`);
        if (menSnapshot) return menSnapshot.payload || menSnapshot;
        const womenSnapshot = offlineState.getSnapshot(MEMBER_PROFILE_SNAPSHOT_MODULE, `women:${normalizedCode}`);
        if (womenSnapshot) return womenSnapshot.payload || womenSnapshot;
    }

    const snapshots = getMemberProfileSnapshots();
    return snapshots.find(entry => entry.member_code.toLowerCase() === normalizedCode) || null;
}

function formatSnapshotAge(cachedAt) {
    const timestamp = new Date(cachedAt).getTime();
    if (!Number.isFinite(timestamp)) return 'recently';

    const minutes = Math.max(0, Math.floor((Date.now() - timestamp) / 60000));
    if (minutes < 1) return 'just now';
    if (minutes === 1) return '1 minute ago';
    if (minutes < 60) return `${minutes} minutes ago`;
    const hours = Math.floor(minutes / 60);
    if (hours === 1) return '1 hour ago';
    if (hours < 24) return `${hours} hours ago`;
    const days = Math.floor(hours / 24);
    return days === 1 ? '1 day ago' : `${days} days ago`;
}

function isSnapshotStale(cachedAt) {
    const timestamp = new Date(cachedAt).getTime();
    return Number.isFinite(timestamp) && (Date.now() - timestamp) > MEMBER_PROFILE_SNAPSHOT_STALE_MS;
}

function redactMemberProfileForOffline(member) {
    if (!member || typeof member !== 'object') return null;

    const redacted = {};
    MEMBER_PROFILE_PUBLIC_FIELDS.forEach(field => {
        if (Object.prototype.hasOwnProperty.call(member, field)) {
            redacted[field] = member[field];
        }
    });

    return {
        ...redacted,
        member_code: String(redacted.member_code || '').trim(),
        name: redacted.name || '',
        membership_type: redacted.membership_type || 'Basic',
        join_date: redacted.join_date || null,
        status: redacted.status || 'active',
        calculated_status: redacted.calculated_status || redacted.status || 'active',
        next_fee_due_date: redacted.next_fee_due_date || null,
        total_due_amount: Number(redacted.total_due_amount || 0),
        is_checked_in: redacted.is_checked_in ?? null
    };
}

function getMemberProfileReadOnlyContext(snapshot = null) {
    const offlineState = getOfflineStateApi();
    const capability = offlineState ? offlineState.getCapabilityStatus('member-profile') : null;
    const renewalStatus = snapshot?.renewal_status || getRenewalStatus();

    return {
        capability,
        renewalStatus,
        isOffline: !Utils.isOnline(),
        isSnapshot: Boolean(snapshot),
        isReadOnly: Boolean(snapshot) || !Utils.isOnline()
    };
}

function renderMemberHistoryNotice(title, message) {
    return `
        <div class="snapshot-notice">
            <strong>${title}</strong>
            <p>${message}</p>
        </div>
    `;
}

async function fetchMemberProfile(memberCode) {
    const res = await fetch(`api/member-profile.php?code=${encodeURIComponent(memberCode)}`);
    const text = await res.text();

    if (!res.ok) {
        throw new Error(`HTTP ${res.status}: ${text.substring(0, 200)}`);
    }

    if (!text) {
        throw new Error('Empty response from server');
    }

    try {
        return JSON.parse(text);
    } catch (e) {
        throw new Error('Invalid JSON response: ' + text.substring(0, 100));
    }
}

function buildSnapshotProfileData(snapshot) {
    if (!snapshot) return null;
    const profile = redactMemberProfileForOffline(snapshot.profile || snapshot.data || snapshot);

    return {
        success: true,
        profile,
        gender: snapshot.gender,
        is_defaulter: snapshot.is_defaulter,
        default_date: snapshot.default_date,
        is_snapshot: true,
        read_only: true,
        snapshot_at: snapshot.cached_at,
        snapshot_age: formatSnapshotAge(snapshot.cached_at),
        snapshot_stale: isSnapshotStale(snapshot.cached_at),
        snapshot_source: snapshot.source || 'snapshot',
        renewal_status: snapshot.renewal_status || null
    };
}

function focusLookupInputAfterRender(clear = true) {
    focusLookupInput({ clear, select: !clear });
}

function getMemberProfileErrorMessage(error, fallbackPrefix) {
    const message = String(error?.message || error || '');
    if (/Failed to fetch|NetworkError|network error/i.test(message)) {
        return 'Member profile lookup is unavailable right now. Please check the connection and try again.';
    }
    return fallbackPrefix ? `${fallbackPrefix}: ${message}` : message;
}

async function handleLookup() {
    const lookupInput = getLookupInput();
    const memberCode = lookupInput ? lookupInput.value.trim() : '';

    if (!memberCode) {
        Utils.showNotification('Please enter member code.', 'error');
        focusLookupInput({ select: true });
        return;
    }

    const loadedProfile = await loadMemberProfile(memberCode);
    if (!loadedProfile || !loadedProfile.profile) {
        focusLookupInput({ select: true });
        return;
    }

    const member = loadedProfile.profile;
    const memberId = member.id;
    const memberGender = loadedProfile.gender;

    console.log('Member found:', { memberId, memberGender, memberCode: member.member_code, snapshot: Boolean(loadedProfile.is_snapshot) });

    if (!memberId || !memberGender) {
        console.error('Invalid member data:', { memberId, memberGender });
        Utils.showNotification('Invalid member data', 'error');
        focusLookupInput({ select: true });
        return;
    }

    // Check in attendance FIRST (same as admin check-in)
    console.log('Attempting check-in:', { memberId, gender: memberGender });
    try {
        const attendancePayload = {
            member_id: memberId,
            gender: memberGender
        };

        const submitCheckIn = window.AttendanceOutbox && typeof window.AttendanceOutbox.submitCheckIn === 'function'
            ? window.AttendanceOutbox.submitCheckIn(attendancePayload)
            : fetch('api/attendance-checkin.php?action=checkin', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(attendancePayload)
            }).then(async res => {
                const text = await res.text();
                if (!res.ok) {
                    try {
                        return JSON.parse(text);
                    } catch {
                        throw new Error('Network error: ' + text.substring(0, 100));
                    }
                }
                return text ? JSON.parse(text) : { success: false, message: 'Empty response' };
            });

        const result = await submitCheckIn;

        console.log('Check-in response:', result);
        if (result.queued) {
            Utils.showNotification('Check-in saved offline. It will replay automatically when the connection returns.', 'warning');
            if (window.AttendanceOutbox) {
                window.AttendanceOutbox.refreshPanels();
            }
            focusLookupInput({ clear: true });
            return;
        }

        if (result.success) {
            Utils.showNotification('Member checked in successfully.', 'success');
            focusLookupInput({ clear: true });
            // Now load the profile after successful check-in
            loadMemberProfile(memberCode);
        } else {
            console.warn('Check-in failed:', result.message);
            // Even if check-in fails (e.g., already checked in), still load the profile
            Utils.showNotification(result.message || 'Profile opened, but check-in status is unclear.', 'info');
            focusLookupInput({ clear: true });
            loadMemberProfile(memberCode);
        }
    } catch (error) {
        console.error('Check-in error:', error);
        // Even if check-in fails, still load the profile
        Utils.showNotification('Check-in had an issue. Opening profile anyway.', 'error');
        focusLookupInput({ clear: true });
        loadMemberProfile(memberCode);
    }
}

async function loadMemberProfile(searchTerm) {
    const contentDiv = document.getElementById('memberContent');
    contentDiv.innerHTML = '<div class="loading">Opening member profile...</div>';

    const memberCode = String(searchTerm || '').trim();
    if (!memberCode) {
        contentDiv.innerHTML = '<div class="error">Please enter member code.</div>';
        return null;
    }

    if (!Utils.isOnline()) {
        const cachedSnapshot = getCachedMemberProfile(memberCode);
        if (cachedSnapshot) {
            const snapshotData = buildSnapshotProfileData(cachedSnapshot);
            if (snapshotData) {
                renderMemberProfile(snapshotData);
                Utils.showNotification('Showing a cached, read-only profile for this recently used member.', 'warning');
                focusLookupInputAfterRender(true);
                return snapshotData;
            }
        }

        const renewalStatus = getRenewalStatus();
        Utils.renderOfflineNotice(
            contentDiv,
            'Member profile offline',
            `Offline lookup only works for recently used members that were already cached on this device. The view is read-only and only stores redacted profile fields.${renewalStatus && renewalStatus.status !== 'fresh' ? ' ' + renewalStatus.message : ''}`
        );
        focusLookupInput({ select: true });
        return null;
    }

    // Load profile directly by exact member code
    try {
        const data = await fetchMemberProfile(memberCode);
        if (data && data.success) {
            const offlineState = getOfflineStateApi();
            if (offlineState && typeof offlineState.recordOnlineSuccess === 'function') {
                offlineState.recordOnlineSuccess('member-profile', { source: 'loadMemberProfile', member_code: memberCode, read_only: true });
            }
            persistMemberProfileSnapshot(data, 'live');
            renderMemberProfile(data);
            focusLookupInputAfterRender(true);
            return data;
        }

        contentDiv.innerHTML = `<div class="error">${data?.message || 'Member not found'}</div>`;
        Utils.showNotification(data?.message || 'Member not found.', 'error');
        focusLookupInput({ select: true });
        return null;
    } catch (err) {
        console.error('Profile error:', err);

        const cachedSnapshot = getCachedMemberProfile(memberCode);
        if (cachedSnapshot) {
            const snapshotData = buildSnapshotProfileData(cachedSnapshot);
            if (snapshotData) {
                renderMemberProfile(snapshotData);
                Utils.showNotification('Loaded a cached, read-only snapshot because live lookup failed.', 'warning');
                focusLookupInputAfterRender(true);
                return snapshotData;
            }
        }

        const message = getMemberProfileErrorMessage(err, 'Could not open member profile');
        contentDiv.innerHTML = `<div class="error">${message}</div>`;
        Utils.showNotification(message, 'error');
        focusLookupInput({ select: true });
        return null;
    }
}

function loadMemberPayments(memberId) {
    const historyContainer = document.getElementById('feeHistoryContainer');
    if (!historyContainer) return;

    historyContainer.innerHTML = '<div class="loading-small">Loading payment history...</div>';

    if (currentMemberData?.isSnapshot) {
        historyContainer.innerHTML = '<div class="error-small">Payment history is intentionally not stored in this read-only cache.</div>';
        return;
    }

    if (!Utils.isOnline()) {
        historyContainer.innerHTML = '<div class="error-small">Payment history is unavailable offline.</div>';
        return;
    }

    // Use the code we already searched for or what's in the data.
    const memberCode = currentMemberData?.code || '';

    // Pass both code and member_id to be safe, plus cache buster
    const cacheBuster = new Date().getTime();
    console.log(`Loading payments for Member ID: ${memberId}, Code: ${memberCode}`);

    fetch(`api/member-profile.php?action=payments&code=${encodeURIComponent(memberCode)}&member_id=${memberId}&_=${cacheBuster}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                allMemberPayments = data.data; // Store globally
                renderFeeHistory(allMemberPayments);
            } else {
                historyContainer.innerHTML = '<div class="error-small">Failed to load payments</div>';
            }
        })
        .catch(err => {
            console.error('Payments load error:', err);
            historyContainer.innerHTML = `<div class="error-small">Error: ${err.message}</div>`;
        });
}

function loadMemberAttendance(memberId) {
    const calendarContainer = document.getElementById('attendanceCalendar');
    if (!calendarContainer) return;

    if (currentMemberData?.isSnapshot) {
        calendarContainer.innerHTML = '<div class="error-small">Attendance history is intentionally not stored in this read-only cache.</div>';
        return;
    }

    if (!Utils.isOnline()) {
        calendarContainer.innerHTML = '<div class="error-small">Attendance calendar is unavailable offline.</div>';
        return;
    }

    // We don't want to wipe the container immediately if we want to show a skeleton,
    // but for now simple loading text or keeping it empty until load is fine.
    // Actually, renderAttendanceCalendar is called in renderMemberProfile with empty data?
    // No, I removed it from renderMemberProfile context in the PHP?
    // Wait, renderMemberProfile calls renderAttendanceCalendar.
    // I need to check renderMemberProfile source.

    const memberCode = currentMemberData?.code || '';
    const year = new Date().getFullYear();
    const month = new Date().getMonth() + 1;

    fetch(`api/member-profile.php?action=attendance&code=${encodeURIComponent(memberCode)}&member_id=${memberId}&year=${year}&month=${month}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                // Update calendar
                const calendarHTML = renderAttendanceCalendar(year, month, data.calendar, currentMemberData.defaultDate);
                calendarContainer.innerHTML = calendarHTML;
            }
        })
        .catch(err => console.error('Attendance load error:', err));
}

// Store member data globally for attendance check-in and pagination
let currentMemberData = null;
let allMemberPayments = [];
let currentPaymentPage = 1;
const PAYMENTS_PER_PAGE = 14;

async function logoutMemberProfile() {
    try {
        await fetch('api/auth.php?action=logout', {
            method: 'POST',
            keepalive: true
        });
    } catch (err) {
        console.error('Logout error:', err);
    } finally {
        sessionStorage.removeItem('gym_last_role');
        sessionStorage.removeItem('gym_last_username');
        sessionStorage.removeItem('gym_last_member_code');
        sessionStorage.removeItem('gym_last_gender');
        clearMemberProfileSnapshotStore();
        await Utils.clearSensitiveCaches();
        window.location.replace('index.html');
    }
}

function renderMemberProfile(data) {
    const member = data.profile || data.data;
    const gender = data.gender || window.MEMBER_GENDER;
    const isSnapshot = Boolean(data.is_snapshot);
    const snapshotAt = data.snapshot_at || null;
    const snapshotAge = data.snapshot_age || (snapshotAt ? formatSnapshotAge(snapshotAt) : null);
    const snapshotStale = Boolean(data.snapshot_stale);
    const isDefaulter = data.is_defaulter || false;
    const defaultDate = data.default_date || member.next_fee_due_date || null;
    const offlineContext = getMemberProfileReadOnlyContext(isSnapshot ? data : null);
    const renewalStatus = offlineContext.renewalStatus;
    const capabilityNotice = offlineContext.capability && window.OfflineState && typeof window.OfflineState.renderCapabilityNotice === 'function' && (!offlineContext.capability.online || (renewalStatus && renewalStatus.status !== 'fresh'))
        ? window.OfflineState.renderCapabilityNotice('member-profile', {
            title: isSnapshot ? 'Read-only cached profile' : 'Offline renewal status',
            body: isSnapshot
                ? `${offlineContext.capability.message} This cached profile only keeps redacted identity and renewal fields.`
                : offlineContext.capability.message
        })
        : '';
    const renewalNote = !isSnapshot && renewalStatus && renewalStatus.status !== 'fresh'
        ? `<div class="snapshot-banner__body snapshot-banner__body--small" style="margin-top:0.5rem;color:${renewalStatus.status === 'expired' ? '#7f1d1d' : '#78350f'};">${renewalStatus.message}</div>`
        : '';
    const snapshotRenewalNote = isSnapshot && data.renewal_status && data.renewal_status.message
        ? `<div class="snapshot-banner__body snapshot-banner__body--small" style="margin-top:0.5rem;color:${data.renewal_status.status === 'expired' ? '#7f1d1d' : '#78350f'};">Synced when renewal was ${String(data.renewal_status.label || 'offline renewal status').toLowerCase()}. ${data.renewal_status.message}</div>`
        : '';

    // Store member data for attendance check-in
    currentMemberData = {
        id: member.id,
        code: member.member_code,
        gender: gender,
        isDefaulter: isDefaulter,
        status: member.status,
        next_fee_due_date: member.next_fee_due_date || null,
        defaultDate,
        isSnapshot,
        snapshotAt,
        snapshotAge,
        snapshotStale,
        source: isSnapshot ? 'snapshot' : 'live',
        readOnly: isSnapshot,
        renewalStatus: renewalStatus || null
    };

    // Store payments for pagination
    allMemberPayments = isSnapshot ? [] : (data.payments || []);
    currentPaymentPage = 1; // Reset to first page

    // Load attendance calendar
    const year = data.attendance?.year || new Date().getFullYear();
    const month = data.attendance?.month || new Date().getMonth() + 1;
    const attendanceCalendar = isSnapshot ? null : (data.attendance?.calendar || {});

    const profileCardClass = isDefaulter ? 'profile-card defaulter' : 'profile-card';
    const snapshotBanner = isSnapshot ? `
        <div class="snapshot-banner ${snapshotStale ? 'snapshot-banner--stale' : 'snapshot-banner--offline'}">
            <div class="snapshot-banner__title">${snapshotStale ? 'Stale read-only offline snapshot' : 'Read-only offline snapshot'}</div>
            <div class="snapshot-banner__body">
                Cached ${snapshotAge || 'recently'}. Live profile reads are unavailable right now.
            </div>
            <div class="snapshot-banner__body snapshot-banner__body--small">
                This cached view keeps only the minimal identity and renewal fields needed at the desk.
            </div>
            ${snapshotRenewalNote}
            ${renewalNote}
        </div>
    ` : '';
    const paymentHistoryHtml = isSnapshot
        ? renderMemberHistoryNotice(
            'Payment history is not cached offline.',
            'Reconnect to see the latest receipts, dues, and payment history for this member.'
        )
        : renderFeeHistory(allMemberPayments);
    const attendanceCalendarHtml = isSnapshot
        ? renderMemberHistoryNotice(
            'Attendance history is not cached offline.',
            'Reconnect to refresh this month’s attendance history and calendar.'
        )
        : renderAttendanceCalendar(year, month, attendanceCalendar, defaultDate);
    const attendanceHint = isSnapshot
        ? 'The cached profile is enough to queue attendance, but the visit history is not stored locally.'
        : 'Attendance is usually marked automatically when you open this profile.';
    const rfidBlock = isSnapshot
        ? ''
        : (member.nfc_uid
            ? `
                            <div class="detail-item" style="background: rgba(67, 105, 255, 0.1); padding: 0.75rem; border-radius: 5px; margin-top: 1rem; border: 1px solid rgba(67, 105, 255, 0.3);">
                                <span class="detail-label" style="color: #4f46e5; font-weight: bold;">📱 RFID Card UID:</span>
                                <span class="detail-value" style="color: #4f46e5; font-family: monospace; font-size: 0.9rem; word-break: break-all;">${member.nfc_uid}</span>
                            </div>
            `
            : `
                            <div class="detail-item" style="background: rgba(156, 163, 175, 0.1); padding: 0.75rem; border-radius: 5px; margin-top: 1rem; border: 1px solid rgba(156, 163, 175, 0.3);">
                                <span class="detail-label" style="color: #6b7280;">📱 RFID Card UID:</span>
                                <span class="detail-value" style="color: #6b7280; font-style: italic;">Not assigned</span>
                            </div>
            `);

    const html = `
        <div class="member-profile">
            ${capabilityNotice}
            ${snapshotBanner}
            <div class="profile-layout" style="display: grid; grid-template-columns: 450px 1fr; gap: 2rem; align-items: start;">
                <!-- Left Side: Profile Info -->
                <div class="profile-sidebar">
                    <div class="${profileCardClass}" id="profileCard">
                        <div class="profile-image">
                            ${!isSnapshot && member.profile_image ?
            `<img src="${member.profile_image}" alt="Profile">` :
            `<div class="profile-placeholder">${member.name ? member.name.charAt(0).toUpperCase() : 'M'}</div>`
        }
                        </div>
                        <div class="profile-details">
                            <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
                                <h1 style="margin:0;">${member.name}</h1>
                                <button class="btn" onclick="logoutMemberProfile()" style="background:#dc2626;">Logout</button>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Member Code:</span>
                                <span class="detail-value">${member.member_code}</span>
                            </div>
                            ${!isSnapshot && member.phone ? `
                            <div class="detail-item">
                                <span class="detail-label">Phone:</span>
                                <span class="detail-value">${member.phone}</span>
                            </div>
                            ` : ''}
                            ${!isSnapshot && member.email ? `
                            <div class="detail-item">
                                <span class="detail-label">Email:</span>
                                <span class="detail-value">${member.email}</span>
                            </div>
                            ` : ''}
                            ${!isSnapshot && member.address ? `
                            <div class="detail-item">
                                <span class="detail-label">Address:</span>
                                <span class="detail-value">${member.address}</span>
                            </div>
                            ` : ''}
                            <div class="detail-item">
                                <span class="detail-label">Membership Type:</span>
                                <span class="detail-value">${member.membership_type}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Join Date:</span>
                                <span class="detail-value">${Utils.formatDate(member.join_date)}</span>
                            </div>
                            ${isSnapshot ? `
                            <div class="detail-item">
                                <span class="detail-label">Cached:</span>
                                <span class="detail-value">${snapshotAge || 'recently'}</span>
                            </div>
                            ` : ''}
                            <div class="detail-item">
                                <span class="detail-label">Status:</span>
                                <span class="status-badge status-${member.status}">${member.status}</span>
                            </div>
                            ${rfidBlock}
                            ${defaultDate ? `
                            <div class="detail-item">
                                <span class="detail-label">Special Due Date:</span>
                                <span class="detail-value" style="color: #8b5cf6; font-weight: bold;">${Utils.formatDate(defaultDate)}</span>
                            </div>
                            ` : ''}
                            ${member.next_fee_due_date ? `
                            <div class="detail-item">
                                <span class="detail-label">Next Fee Due:</span>
                                <span class="detail-value">${Utils.formatDate(member.next_fee_due_date)}</span>
                            </div>
                            ` : ''}
                            ${isDefaulter ? `
                            <div class="detail-item" style="background: rgba(220, 53, 69, 0.2); padding: 0.75rem; border-radius: 5px; margin-top: 1rem; border: 1px solid #dc3545;">
                                <span class="detail-label" style="color: #dc3545; font-weight: bold;">⚠️ Payment Status</span>
                                <span class="detail-value" style="color: #dc3545; font-weight: bold;">Unpaid for 30+ days</span>
                            </div>
                            ` : ''}
                            ${member.total_due_amount > 0 ? `
                            <div class="detail-item" style="background: rgba(255, 0, 0, 0.1); padding: 0.75rem; border-radius: 5px; margin-top: 1rem;">
                                <span class="detail-label" style="color: red; font-weight: bold;">Total Unpaid Amount:</span>
                                <span class="detail-value" style="color: red; font-weight: bold; font-size: 1.25rem;">${Utils.formatCurrency(member.total_due_amount)}</span>
                            </div>
                            ` : ''}
                        </div>
                    </div>
                </div>

                <!-- Right Side: Payment History -->
                <div class="profile-main-content">
                    <div class="fee-section" style="margin-top: 0;">
                        <h2 style="margin-top: 0;">Payment History</h2>
                        <div class="fee-history" id="feeHistoryContainer">
                            ${paymentHistoryHtml}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bottom: Attendance This Month -->
            <div class="calendar-wrapper" style="margin-top: 2rem; padding: 0 2rem; display: flex; justify-content: center;">
                <div class="calendar-section" style="width: 100%; max-width: 600px;">
                    <h2>Attendance This Month</h2>
                    <div class="attendance-calendar" id="attendanceCalendar">
                        ${attendanceCalendarHtml}
                    </div>
                    <div style="margin-top: 1rem; padding: 1rem; background: rgba(67, 105, 255, 0.08); border-radius: 8px; border: 1px solid rgba(67, 105, 255, 0.4); display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;">
                        <div style="flex: 1 1 280px;">
                            <p style="margin: 0 0 0.5rem 0; color: #8b5cf6; font-weight: bold;">Attendance</p>
                            <p style="margin: 0; color: var(--text-secondary);">
                                ${attendanceHint}
                            </p>
                        </div>
                        <button onclick="checkInAttendance()" style="padding: 0.75rem 2rem; background: #4f46e5; color: white; border: none; border-radius: 5px; cursor: pointer; font-weight: bold; transition: all 0.3s; white-space: nowrap;">Mark Check-In</button>
                    </div>
                    <div data-attendance-outbox-panel aria-live="polite"></div>
                </div>
            </div>
        </div>
    `;

    document.getElementById('memberContent').innerHTML = html;
    if (window.AttendanceOutbox && typeof window.AttendanceOutbox.refreshPanels === 'function') {
        window.AttendanceOutbox.refreshPanels();
    }
}

function renderAttendanceCalendar(year, month, attendanceData, defaultDate = null) {
    const firstDay = new Date(year, month - 1, 1);
    const lastDay = new Date(year, month, 0);
    const daysInMonth = lastDay.getDate();
    const startDayOfWeek = firstDay.getDay();

    const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'];

    let html = `
        <div class="calendar-header">
            <h3>${monthNames[month - 1]} ${year}</h3>
        </div>
        <div class="calendar-grid">
            <div class="calendar-weekday">Sun</div>
            <div class="calendar-weekday">Mon</div>
            <div class="calendar-weekday">Tue</div>
            <div class="calendar-weekday">Wed</div>
            <div class="calendar-weekday">Thu</div>
            <div class="calendar-weekday">Fri</div>
            <div class="calendar-weekday">Sat</div>
    `;

    // Empty cells for days before month starts
    for (let i = 0; i < startDayOfWeek; i++) {
        html += '<div class="calendar-day empty"></div>';
    }

    // Days of the month - FIX: Use local date for "Today", not UTC
    const today = new Date();
    const currentYear = today.getFullYear();
    const currentMonth = today.getMonth() + 1;
    const currentDay = today.getDate();
    const todayStr = `${currentYear}-${String(currentMonth).padStart(2, '0')}-${String(currentDay).padStart(2, '0')}`;

    // Parse default date if provided
    let defaultDateStr = null;
    if (defaultDate) {
        const defaultDateObj = new Date(defaultDate);
        if (defaultDateObj.getFullYear() === year && defaultDateObj.getMonth() + 1 === month) {
            defaultDateStr = `${year}-${String(month).padStart(2, '0')}-${String(defaultDateObj.getDate()).padStart(2, '0')}`;
        }
    }

    for (let day = 1; day <= daysInMonth; day++) {
        const dateStr = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        const isToday = dateStr === todayStr;
        const isDefaultDate = dateStr === defaultDateStr;
        const isFuture = (year > currentYear) ||
            (year === currentYear && month > currentMonth) ||
            (year === currentYear && month === currentMonth && day > currentDay);

        // Only show attendance status for past dates and today
        let dayClass = '';

        // Default date should be highlighted with violet
        if (isDefaultDate) {
            dayClass = 'default-date';
        }

        // Today's date should always be highlighted first
        if (isToday) {
            dayClass = 'today';
            // Then add attendance status
            if (!isFuture) {
                const hasAttendance = attendanceData[dateStr] && attendanceData[dateStr] > 0;
                dayClass += hasAttendance ? ' present' : ' absent';
            }
            // If today is also default date
            if (isDefaultDate) {
                dayClass += ' default-date';
            }
        } else if (isFuture) {
            if (!isDefaultDate) {
                dayClass = 'future';
            }
        } else {
            if (!isDefaultDate) {
                const hasAttendance = attendanceData[dateStr] && attendanceData[dateStr] > 0;
                dayClass = hasAttendance ? 'present' : 'absent';
            }
        }

        html += `
            <div class="calendar-day ${dayClass}">
                <span class="day-number">${day}</span>
                ${isToday ? '<span class="today-indicator">Today</span>' : ''}
            </div>
        `;
    }

    html += '</div>';
    return html;
}

// Function to check in attendance manually (from the button on profile page)
function checkInAttendance() {
    if (!currentMemberData) {
        Utils.showNotification('Member data is not loaded yet.', 'error');
        return;
    }

    // Play beep sound
    playBeepSound();

    // Make API call to check in (same as admin check-in)
    const attendancePayload = {
        member_id: currentMemberData.id,
        gender: currentMemberData.gender
    };

    const submitCheckIn = window.AttendanceOutbox && typeof window.AttendanceOutbox.submitCheckIn === 'function'
        ? window.AttendanceOutbox.submitCheckIn(attendancePayload)
        : fetch('api/attendance-checkin.php?action=checkin', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(attendancePayload)
        }).then(async res => {
            const text = await res.text();
            if (!res.ok) {
                try {
                    return JSON.parse(text);
                } catch {
                    throw new Error('Network error: ' + text.substring(0, 100));
                }
            }
            return text ? JSON.parse(text) : { success: false, message: 'Empty response' };
        });

    submitCheckIn
        .then(data => {
            if (data.queued) {
                Utils.showNotification('Check-in saved offline. It will replay automatically when the connection returns.', 'warning');
                if (window.AttendanceOutbox) {
                    window.AttendanceOutbox.refreshPanels();
                }
                focusLookupInput({ clear: true });
                return;
            }

            if (data.success) {
                Utils.showNotification('Member checked in successfully.', 'success');
                focusLookupInput({ clear: true });

                // Reload profile to update calendar
                setTimeout(() => {
                    loadMemberProfile(currentMemberData.code);
                }, 600);
            } else {
                Utils.showNotification(data.message || 'Failed to record check-in', 'error');
                focusLookupInput({ select: true });
            }
        })
        .catch(err => {
            console.error('Check-in error:', err);
            Utils.showNotification('Failed to record check-in: ' + err.message, 'error');
            focusLookupInput({ select: true });
        });
}

// Function to play beep sound
function playBeepSound() {
    // Create audio context for beep sound
    const audioContext = new (window.AudioContext || window.webkitAudioContext)();
    const oscillator = audioContext.createOscillator();
    const gainNode = audioContext.createGain();

    oscillator.connect(gainNode);
    gainNode.connect(audioContext.destination);

    oscillator.frequency.value = 800; // Beep frequency
    oscillator.type = 'sine';

    gainNode.gain.setValueAtTime(0.3, audioContext.currentTime);
    gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.3);

    oscillator.start(audioContext.currentTime);
    oscillator.stop(audioContext.currentTime + 0.3);
}

function renderFeeHistory(payments) {
    if (!payments || payments.length === 0) {
        return '<div class="no-data"><p>No payment history found yet.</p></div>';
    }

    // Sort payments by date (newest first)
    const sortedPayments = [...payments].sort((a, b) => {
        return new Date(b.payment_date) - new Date(a.payment_date);
    });

    const totalPages = Math.ceil(sortedPayments.length / PAYMENTS_PER_PAGE);
    const startIndex = (currentPaymentPage - 1) * PAYMENTS_PER_PAGE;
    const visiblePayments = sortedPayments.slice(startIndex, startIndex + PAYMENTS_PER_PAGE);

    let html = `
        <table class="fee-table" style="table-layout: fixed; width: 100%;">
            <thead>
                <tr>
                    <th style="width: 15%;">Payment Date</th>
                    <th style="width: 15%;">Amount Paid</th>
                    <th style="width: 12%;">Method</th>
                    <th style="width: 13%;">Remaining</th>
                    <th style="width: 15%;">Due Date</th>
                    <th style="width: 20%;">Invoice #</th>
                    <th style="width: 10%;">Status</th>
                </tr>
            </thead>
            <tbody>
                ${visiblePayments.map(p => {
        const remainingDue = parseFloat(p.remaining_amount) || 0;
        return `
                    <tr style="height: 50px;">
                        <td>${Utils.formatDate(p.payment_date)}</td>
                        <td><strong>${Utils.formatCurrency(p.amount)}</strong></td>
                        <td>${p.payment_method || 'Cash'}</td>
                        <td>${remainingDue > 0 ? `<span style="color: red; font-weight: bold;">${Utils.formatCurrency(remainingDue)}</span>` : '<span style="color: green;">Paid</span>'}</td>
                        <td>${p.due_date ? Utils.formatDate(p.due_date) : 'N/A'}</td>
                        <td style="font-size: 0.85rem; word-break: break-all;">${p.invoice_number || 'N/A'}</td>
                        <td><span class="status-badge status-${p.status}">${p.status}</span></td>
                    </tr>
                `;
    }).join('')}
                ${visiblePayments.length < PAYMENTS_PER_PAGE ?
            Array(PAYMENTS_PER_PAGE - visiblePayments.length).fill(
                '<tr style="height: 50px;"><td colspan="7">&nbsp;</td></tr>'
            ).join('')
            : ''}
            </tbody>
        </table>
    `;

    // Pagination Controls
    if (totalPages > 1) {
        html += `
            <div class="pagination" style="display: flex; justify-content: center; gap: 1rem; margin-top: 1rem;">
                <button class="btn btn-sm btn-secondary"
                    onclick="changePaymentPage(-1)"
                    ${currentPaymentPage === 1 ? 'disabled' : ''}>
                    &laquo; Prev
                </button>
                <span style="align-self: center; color: var(--text-secondary);">
                    Page ${currentPaymentPage} of ${totalPages}
                </span>
                <button class="btn btn-sm btn-secondary"
                    onclick="changePaymentPage(1)"
                    ${currentPaymentPage === totalPages ? 'disabled' : ''}>
                    Next &raquo;
                </button>
            </div>
        `;
    }

    return html;
}

function changePaymentPage(direction) {
    const totalPages = Math.ceil(allMemberPayments.length / PAYMENTS_PER_PAGE);
    const newPage = currentPaymentPage + direction;

    if (newPage >= 1 && newPage <= totalPages) {
        currentPaymentPage = newPage;
        const container = document.getElementById('feeHistoryContainer');
        if (container) {
            container.innerHTML = renderFeeHistory(allMemberPayments);
        }
    }
}
