/**
 * Authentication JavaScript
 */

document.addEventListener('DOMContentLoaded', function () {
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
