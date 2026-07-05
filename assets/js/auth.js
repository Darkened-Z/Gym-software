/**
 * Authentication JavaScript
 */

// Partial lock: when the gym is LOCKED, staff are blocked but members can still
// sign in — so we show a top banner, not a full-page wipe (forms stay usable).
function showStaffLockBanner() {
    if (document.getElementById('subLockBanner')) return;
    var b = document.createElement('div');
    b.id = 'subLockBanner';
    b.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:99999;background:#0d0d0d;color:#fff;padding:.85rem 1rem;text-align:center;font-family:-apple-system,Segoe UI,Roboto,sans-serif;border-bottom:3px solid #f5c518;font-size:.95rem;display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:.6rem;';
    b.innerHTML = '🔑 <strong>Reactivate License</strong> — your license has expired. Reactivate it to restore front-desk access. '
        + '<span style="opacity:.85">Members can still sign in below.</span>'
        + '<a href="setup.php" style="display:inline-block;background:#f5c518;color:#0d0d0d;padding:.45rem .95rem;border-radius:8px;font-weight:700;text-decoration:none;">Reactivate License</a>';
    document.body.appendChild(b);
    document.body.style.paddingTop = '76px';
}

// Pre-expiry heads-up on the login page (3-day countdown: 3 → 2 → 1 → 0).
function showLicenseWarnBanner(daysLeft) {
    if (document.getElementById('licWarnBanner')) return;
    var b = document.createElement('div');
    b.id = 'licWarnBanner';
    b.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:99998;background:#fde68a;color:#1f2937;padding:.7rem 1rem;text-align:center;font-weight:600;font-size:.9rem;font-family:-apple-system,Segoe UI,Roboto,sans-serif;';
    b.textContent = '⏳ Your license expires in ' + daysLeft + ' day' + (daysLeft === 1 ? '' : 's') + ' — renew now.';
    document.body.appendChild(b);
    document.body.style.paddingTop = '48px';
}

document.addEventListener('DOMContentLoaded', function () {
    // If LOCKED, flag staff access (members are unaffected and can still log in).
    if ((document.getElementById('adminForm') || document.getElementById('memberForm'))
        && window.Utils && Utils.isOnline && Utils.isOnline()) {
        fetch('api/auth.php?action=license_status')
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d) return;
                if (d.locked) {
                    showStaffLockBanner();
                } else if (d.activated && !d.expired && d.days_left !== null && d.days_left >= 0 && d.days_left <= 3) {
                    showLicenseWarnBanner(d.days_left);
                }
            })
            .catch(function () { });
    }

    const adminForm = document.getElementById('adminForm');
    const memberForm = document.getElementById('memberForm');

    if (adminForm) {
        adminForm.addEventListener('submit', function (e) {
            e.preventDefault();
            handleAdminLogin();
        });
    }

    if (memberForm) {
        memberForm.addEventListener('submit', function (e) {
            e.preventDefault();
            handleMemberLogin();
        });
    }
});

function handleAdminLogin() {
    const username = document.getElementById('adminUsername').value.trim();
    const password = document.getElementById('adminPassword').value;
    const submitBtn = document.querySelector('#adminForm button[type="submit"]');

    if (!Utils.isOnline()) {
        Utils.showNotification('You are offline. Staff login needs an internet connection.', 'warning');
        return;
    }

    // Validate inputs
    if (!username || !password) {
        Utils.showNotification('Please enter your username and password.', 'error');
        return;
    }

    if (username.length < 3) {
        Utils.showNotification('Username must be at least 3 characters', 'error');
        return;
    }

    if (password.length < 3) {
        Utils.showNotification('Password must be at least 3 characters', 'error');
        return;
    }

    // Show loading state
    Utils.setButtonLoading(submitBtn, true);

    Utils.apiPost('api/auth.php?action=login', { username, password })
        .then(data => {
            if (data.success) {
                if (window.OfflineState && typeof window.OfflineState.recordOnlineSuccess === 'function') {
                    window.OfflineState.recordOnlineSuccess('auth', { source: 'handleAdminLogin' });
                }
                sessionStorage.setItem('gym_last_role', data.role || 'staff');
                sessionStorage.setItem('gym_last_username', data.username || username);
                Utils.showNotification('Login successful. Opening dashboard...', 'success');
                setTimeout(() => {
                    if (data.role === 'admin' || data.role === 'staff') {
                        window.location.href = 'admin-dashboard.html';
                    } else {
                        window.location.href = 'index.html';
                    }
                }, 500);
            } else if (data.error_code === 'SUBSCRIPTION_EXPIRED') {
                showStaffLockBanner();
            } else {
                Utils.showNotification(data.message || 'Login failed', 'error');
            }
        })
        .catch(err => {
            console.error('Login error:', err);
            Utils.showNotification('An error occurred during login. Please try again.', 'error');
        })
        .finally(() => {
            // Remove loading state
            const submitBtn = document.querySelector('#adminForm button[type="submit"]');
            if (submitBtn) Utils.setButtonLoading(submitBtn, false);
        });
}

function handleMemberLogin() {
    const memberCode = document.getElementById('memberCode').value.trim();
    const submitBtn = document.querySelector('#memberForm button[type="submit"]');

    if (!Utils.isOnline()) {
        Utils.showNotification('You are offline. Member sign-in needs an internet connection.', 'warning');
        return;
    }

    // Validate input
    if (!memberCode) {
        Utils.showNotification('Please enter member code or account number.', 'error');
        return;
    }

    if (memberCode.length < 2) {
        Utils.showNotification('Please enter a valid member code', 'error');
        return;
    }

    // Show loading state
    Utils.setButtonLoading(submitBtn, true);

    Utils.apiPost('api/auth.php?action=login', { member_code: memberCode })
        .then(data => {
            if (data.success) {
                if (window.OfflineState && typeof window.OfflineState.recordOnlineSuccess === 'function') {
                    window.OfflineState.recordOnlineSuccess('auth', { source: 'handleMemberLogin' });
                }
                sessionStorage.setItem('gym_last_role', 'member');
                sessionStorage.setItem('gym_last_member_code', memberCode);
                sessionStorage.setItem('gym_last_gender', data.gender || 'men');
                Utils.showNotification('Login successful. Opening profile...', 'success');
                setTimeout(() => {
                    if (data.gender === 'men') {
                        window.location.href = 'member-profile-men.html';
                    } else {
                        window.location.href = 'member-profile-women.html';
                    }
                }, 500);
            } else if (data.error_code === 'SUBSCRIPTION_EXPIRED') {
                showStaffLockBanner();
            } else {
                Utils.showNotification(data.message || 'Invalid member code', 'error');
            }
        })
        .catch(err => {
            console.error('Login error:', err);
            Utils.showNotification('An error occurred during login. Please try again.', 'error');
        })
        .finally(() => {
            // Remove loading state
            const submitBtn = document.querySelector('#memberForm button[type="submit"]');
            if (submitBtn) Utils.setButtonLoading(submitBtn, false);
        });
}

async function handleLogout() {
    try {
        await fetch('api/auth.php?action=logout', {
            method: 'POST'
        });
    } catch (err) {
        console.error('Logout error:', err);
    } finally {
        localStorage.clear();
        sessionStorage.removeItem('gym_last_role');
        sessionStorage.removeItem('gym_last_username');
        sessionStorage.removeItem('gym_last_member_code');
        sessionStorage.removeItem('gym_last_gender');
        await Utils.clearSensitiveCaches();
        window.location.href = 'index.html';
    }
}
