/**
 * Admin Dashboard JavaScript
 */

let currentSection = 'dashboard';
let currentGender = 'men';
let currentUserRole = null;
let staffSection = 'both'; // 'men' | 'women' | 'both' — this staff member's section access
let activeRequests = {}; // Track active fetch requests to cancel them if needed
let isLoadingDashboard = false; // Prevent multiple simultaneous dashboard loads
let memberStatusFilter = null; // 'active', 'inactive', or null for all
let paymentsDefaultersFilter = false; // Show defaulters or regular payments
let sectionRefreshInterval = null; // Lightweight real-time refresh for live sections

document.addEventListener('DOMContentLoaded', function () {
    checkAuth();
    checkLicenseWarning();
    setupSectionGuard();
    setupNavigation();
    setupMobileMenu();
    loadDashboard();
    startSectionAutoRefresh();
    startAutoSync(); // Start auto-sync timer
    bindOfflineOutboxRefresh();

    window.addEventListener('online', () => {
        if (currentSection === 'dashboard') {
            loadDashboard();
        } else if (currentSection === 'members' && !document.querySelector('.modal')) {
            loadMembers();
        }
        startSectionAutoRefresh();
        startAutoSync();
    });

    window.addEventListener('offline', () => {
        stopSectionAutoRefresh();
    });

    window.addEventListener('attendance-outbox:flush-end', event => {
        const detail = event?.detail || {};
        if ((detail.replayed || 0) <= 0 && (detail.dropped || 0) <= 0) {
            return;
        }
        if (currentSection === 'attendance') {
            loadAttendanceTable();
        } else if (currentSection === 'dashboard') {
            loadDashboard();
        }
    });

    window.addEventListener('member-write-outbox:flush-end', event => {
        const detail = event?.detail || {};
        if ((detail.replayed || 0) <= 0 && (detail.dropped || 0) <= 0) {
            return;
        }
        if (currentSection === 'members' && !document.querySelector('.modal')) {
            const currentPage = parseInt(document.getElementById('membersPageInput')?.value || '1', 10) || 1;
            loadMembersTable(currentPage);
        } else if (currentSection === 'dashboard') {
            loadDashboard();
        }
    });

    window.addEventListener('payment-outbox:flush-end', event => {
        const detail = event?.detail || {};
        if ((detail.replayed || 0) <= 0 && (detail.dropped || 0) <= 0) {
            return;
        }
        if (currentSection === 'payments' && !document.querySelector('.modal')) {
            loadPaymentsTable();
        } else if (currentSection === 'members' && !document.querySelector('.modal')) {
            const currentPage = parseInt(document.getElementById('membersPageInput')?.value || '1', 10) || 1;
            loadMembersTable(currentPage);
        } else if (currentSection === 'due-fees' && !document.querySelector('.modal')) {
            loadDueFeesTable();
        } else if (currentSection === 'dashboard') {
            loadDashboard();
        }
    });

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            startSectionAutoRefresh();
        }
    });

    const logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', handleLogout);
    }
});

function setupMobileMenu() {
    const mobileToggle = document.getElementById('mobileMenuToggle');
    const sidebar = document.getElementById('sidebar');
    const contentBody = document.getElementById('contentBody');

    if (mobileToggle && sidebar) {
        mobileToggle.addEventListener('click', function () {
            sidebar.classList.toggle('open');
        });

        // Close sidebar when clicking outside on mobile
        if (contentBody) {
            contentBody.addEventListener('click', function (e) {
                if (window.innerWidth <= 768 && sidebar.classList.contains('open')) {
                    if (!sidebar.contains(e.target) && !mobileToggle.contains(e.target)) {
                        sidebar.classList.remove('open');
                    }
                }
            });
        }

        // Close sidebar when clicking a nav item on mobile
        document.querySelectorAll('.nav-item').forEach(item => {
            item.addEventListener('click', function () {
                if (window.innerWidth <= 768) {
                    sidebar.classList.remove('open');
                }
            });
        });
    }
}

function applyRolePermissions() {
    const hiddenSectionsByRole = {
        staff: ['staff', 'registrations', 'activity-log', 'import', 'sync', 'reminders']
    };

    const hiddenSections = hiddenSectionsByRole[currentUserRole] || [];
    document.querySelectorAll('.nav-item[data-section]').forEach(item => {
        const section = item.dataset.section;
        item.style.display = hiddenSections.includes(section) ? 'none' : '';
    });

    if (hiddenSections.includes(currentSection)) {
        switchSection('dashboard');
    }

    // Section access: a men- or women-only staff is locked to their side.
    if (staffSection === 'men' || staffSection === 'women') {
        currentGender = staffSection;
        hideDisallowedGenderTabs();
    }
}

// Hide the other section's men/women tabs for a section-restricted staff member.
function hideDisallowedGenderTabs() {
    if (staffSection !== 'men' && staffSection !== 'women') return;
    const blocked = staffSection === 'men' ? 'women' : 'men';
    document.querySelectorAll('.gender-tab[data-gender="' + blocked + '"], .gender-tab[data-dashboard-recent-tab="' + blocked + '"]')
        .forEach(function (t) { t.style.display = 'none'; });
    if (currentGender !== staffSection) currentGender = staffSection;
}

function setupSectionGuard() {
    var cb = document.getElementById('contentBody');
    if (cb && window.MutationObserver) {
        new MutationObserver(function () { hideDisallowedGenderTabs(); }).observe(cb, { childList: true, subtree: true });
    }
}

function checkAuth() {
    if (!Utils.isOnline()) {
        const storedRole = sessionStorage.getItem('gym_last_role');
        const storedName = sessionStorage.getItem('gym_last_username');
        if (['admin', 'staff'].includes(storedRole)) {
            currentUserRole = storedRole;
            staffSection = sessionStorage.getItem('gym_last_section') || 'both';
            const userName = document.getElementById('userName');
            if (userName) {
                userName.textContent = storedName || (storedRole === 'staff' ? 'Staff' : 'Admin');
            }
            applyRolePermissions();
        }
        return;
    }

    fetch('api/auth.php?action=check')
        .then(res => res.json())
        .then(data => {
            if (!data.authenticated || !['admin', 'staff'].includes(data.role)) {
                window.location.href = 'index.html';
            } else {
                currentUserRole = data.role;
                staffSection = data.staff_section || 'both';
                sessionStorage.setItem('gym_last_role', data.role);
                sessionStorage.setItem('gym_last_section', staffSection);
                sessionStorage.setItem('gym_last_username', data.username || data.name || (data.role === 'staff' ? 'Staff' : 'Admin'));
                const userName = document.getElementById('userName');
                if (userName) {
                    userName.textContent = data.username || data.name || (data.role === 'staff' ? 'Staff' : 'Admin');
                }
                applyRolePermissions();
            }
        })
        .catch(err => {
            console.error('Auth check error:', err);
            if (!Utils.isOnline()) {
                return;
            }
            window.location.href = 'index.html';
        });
}

function checkLicenseWarning() {
    if (!window.Utils || !Utils.isOnline || !Utils.isOnline()) return;
    fetch('api/auth.php?action=license_status')
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.success) return;
            if (d.locked) { window.location.href = 'index.html'; return; } // safety net
            var msg = null, urgent = false;
            if (d.in_grace) {
                urgent = true;
                msg = '⚠ Your subscription has expired. Front-desk access will lock in '
                    + d.grace_left + ' day' + (d.grace_left === 1 ? '' : 's') + ' — please renew now.';
            } else if (!d.expired && d.days_left !== null && d.days_left >= 0 && d.days_left <= 3) {
                msg = '⏳ Your license expires in ' + d.days_left + ' day' + (d.days_left === 1 ? '' : 's')
                    + ' — renew now.';
            }
            if (msg) showLicenseBanner(msg, urgent);
        })
        .catch(function () { });
}

function showLicenseBanner(msg, urgent) {
    if (document.getElementById('licBanner')) return;
    var b = document.createElement('div');
    b.id = 'licBanner';
    b.style.cssText = 'position:sticky;top:0;z-index:50;padding:.7rem 1rem;text-align:center;font-weight:600;font-size:.9rem;'
        + (urgent ? 'background:#7c2d12;color:#fff;' : 'background:#fde68a;color:#1f2937;');
    b.textContent = msg;
    document.body.insertBefore(b, document.body.firstChild);
}

function setupNavigation() {
    document.querySelectorAll('.nav-item').forEach(item => {
        if (item.id !== 'logoutBtn') {
            item.addEventListener('click', function (e) {
                e.preventDefault();
                const section = this.dataset.section;
                switchSection(section);
            });
        }
    });
}

function isAdminUser() {
    return currentUserRole === 'admin';
}

function requireAdminAccess(actionText = 'perform this action') {
    if (isAdminUser()) return true;
    Utils.showNotification(`Only admin can ${actionText}.`, 'error');
    return false;
}

function renderSectionGuideCard({ chip = 'Quick Help', title, description, steps = [], actions = '' }) {
    return `
        <div class="section-guide">
            <span class="page-chip">${chip}</span>
            <h2>${title}</h2>
            <p>${description}</p>
            ${steps.length ? `<ul class="helper-list">${steps.map(step => `<li>${step}</li>`).join('')}</ul>` : ''}
            ${actions ? `<div class="quick-actions-bar">${actions}</div>` : ''}
        </div>
    `;
}

function stopSectionAutoRefresh() {
    if (sectionRefreshInterval) {
        clearInterval(sectionRefreshInterval);
        sectionRefreshInterval = null;
    }
}

function startSectionAutoRefresh() {
    stopSectionAutoRefresh();

    const liveSections = {
        'dashboard': { interval: 30000, refresh: () => loadDashboard() },
        'attendance': { interval: 15000, refresh: () => loadAttendanceTable() },
        'due-fees': { interval: 30000, refresh: () => loadDueFeesTable() }
    };

    const config = liveSections[currentSection];
    if (!config) return;

    sectionRefreshInterval = setInterval(() => {
        if (document.hidden) return;
        if (document.querySelector('.modal')) return;
        if (!Utils.isOnline()) return;

        try {
            config.refresh();
        } catch (err) {
            console.error(`Live refresh failed for ${currentSection}:`, err);
        }
    }, config.interval);
}

function switchSection(section) {
    const blockedSectionsByRole = {
        staff: ['staff', 'registrations', 'activity-log', 'import', 'sync', 'reminders']
    };
    if ((blockedSectionsByRole[currentUserRole] || []).includes(section)) {
        Utils.showNotification('This section is available for admin only.', 'error');
        return;
    }

    // Don't reload if already on this section
    if (currentSection === section && document.getElementById('contentBody').innerHTML !== '<div class="loading">Loading...</div>') {
        startSectionAutoRefresh();
        return;
    }

    stopSectionAutoRefresh();
    currentSection = section;

    // Cancel all active requests when switching sections
    Object.keys(activeRequests).forEach(key => {
        if (activeRequests[key]) {
            activeRequests[key].abort();
            delete activeRequests[key];
        }
    });

    // Update active nav item
    document.querySelectorAll('.nav-item').forEach(item => {
        item.classList.remove('active');
    });
    const navItem = document.querySelector(`[data-section="${section}"]`);
    if (navItem) {
        navItem.classList.add('active');
    }

    // Update page title
    const titles = {
        'dashboard': 'Home Dashboard',
        'members': 'Members',
        'registrations': 'New Member Requests',
        'attendance': 'Check In / Out',
        'payments': 'Payments',
        'due-fees': 'Members Who Need to Pay',
        'expenses': 'Money Spent',
        'details': 'Details',
        'reports': 'Reports',
        'staff': 'Staff',
        'activity-log': 'Activity Log',
        'import': 'Import / Download',
        'sync': 'Sync / Backup',
        'reminders': 'WhatsApp Reminders'
    };
    const pageTitle = document.getElementById('pageTitle');
    if (pageTitle) {
        pageTitle.textContent = titles[section] || 'Home';
    }

    // Load section content
    loadSection(section);
    startSectionAutoRefresh();
}

function loadSection(section) {
    // Cancel any pending requests for this section
    if (activeRequests[section]) {
        activeRequests[section].abort();
        delete activeRequests[section];
    }

    const contentBody = document.getElementById('contentBody');
    if (!contentBody) return;

    contentBody.innerHTML = '<div class="loading">Loading...</div>';

    if (!Utils.isOnline() && section !== 'dashboard') {
        Utils.renderOfflineNotice(
            contentBody,
            `${section.replace(/-/g, ' ')} unavailable offline`,
            'This section uses live data or mutations, so it is intentionally left out of the offline cache for safety.'
        );
        return;
    }

    switch (section) {
        case 'dashboard':
            loadDashboard();
            break;
        case 'members':
            loadMembers();
            break;
        case 'registrations':
            loadRegistrations();
            break;
        case 'attendance':
            loadAttendance();
            break;
        case 'payments':
            loadPayments();
            break;
        case 'due-fees':
            loadDueFees();
            break;
        case 'expenses':
            loadExpenses();
            break;
        case 'details':
            loadDetails();
            break;
        case 'reports':
            loadReports();
            break;
        case 'staff':
            loadStaff();
            break;
        case 'activity-log':
            loadActivityLog();
            break;
        case 'import':
            loadImport();
            break;
        case 'sync':
            loadSync();
            break;
        case 'reminders':
            loadReminders();
            break;
    }
}

function loadReminders() {
    const contentBody = document.getElementById('contentBody');
    if (!contentBody) return;

    contentBody.innerHTML = `
        <div class="section-card">
            ${renderSectionGuideCard({
                chip: 'Reminder Help',
                title: 'Prepare WhatsApp fee reminders',
                description: 'Use this when you want to prepare due or overdue reminder entries for members.',
                steps: [
                    'Prepare Due Reminders for upcoming dues.',
                    'Prepare Overdue Reminders for late payments.',
                    'Check the pending list after creating reminders.'
                ]
            })}
            <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
                <div>
                    <h2>WhatsApp Reminders</h2>
                    <p>Prepare fee due and overdue reminders for active members.</p>
                </div>
                <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
                    <button class="btn btn-primary" onclick="queueFeeReminders('fee_due')">Prepare Due Reminders</button>
                    <button class="btn btn-warning" onclick="queueFeeReminders('fee_overdue')">Prepare Overdue Reminders</button>
                </div>
            </div>
            <div id="reminderStats" style="margin-top:1rem;">Loading reminder stats...</div>
            <div id="reminderQueue" style="margin-top:1rem;">Loading pending reminder queue...</div>
        </div>
    `;

    Promise.all([
        fetch('api/reminders.php?action=stats').then(r => r.json()),
        fetch('api/reminders.php?action=pending&limit=20').then(r => r.json())
    ]).then(([statsRes, queueRes]) => {
        const statsEl = document.getElementById('reminderStats');
        const queueEl = document.getElementById('reminderQueue');

        if (statsEl) {
            const stats = statsRes.data || {};
            statsEl.innerHTML = `
                <div class="stats-grid">
                    <div class="stat-card"><h3>Pending</h3><p>${stats.pending_count || 0}</p></div>
                    <div class="stat-card"><h3>Sent</h3><p>${stats.sent_count || 0}</p></div>
                    <div class="stat-card"><h3>Failed</h3><p>${stats.failed_count || 0}</p></div>
                </div>
            `;
        }

        if (queueEl) {
            const rows = (queueRes.data || []).map(item => `
                <tr>
                    <td>${item.id}</td>
                    <td>${item.recipient}</td>
                    <td>${item.message_purpose}</td>
                    <td>${item.status}</td>
                    <td>${item.scheduled_for}</td>
                </tr>
            `).join('');

            queueEl.innerHTML = `
                <table class="data-table">
                    <thead>
                        <tr><th>ID</th><th>Recipient</th><th>Purpose</th><th>Status</th><th>Scheduled</th></tr>
                    </thead>
                    <tbody>${rows || '<tr><td colspan="5">No pending reminders</td></tr>'}</tbody>
                </table>
            `;
        }
    }).catch(err => {
        console.error('Reminder load error:', err);
    });
}

function queueFeeReminders(purpose) {
    fetch(`api/reminders.php?action=queue-fee-reminders&gender=${currentGender}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ purpose, gym_name: 'Your Gym' })
    })
    .then(r => r.json())
    .then(data => {
        alert(data.message || 'Queue action completed');
        loadReminders();
    })
    .catch(err => {
        console.error('Queue reminder error:', err);
        alert('Failed to queue reminders');
    });
}

function loadDashboard() {
    // Prevent multiple simultaneous dashboard loads
    if (isLoadingDashboard && activeRequests['dashboard']) {
        return;
    }

    if (!Utils.isOnline() && !sessionStorage.getItem('gym_last_role')) {
        isLoadingDashboard = false;
        Utils.renderOfflineNotice(
            '#contentBody',
            'Dashboard unavailable offline',
            'Sign in online at least once from this browser session to unlock the cached dashboard snapshot. The shell is still available.'
        );
        return;
    }

    // Cancel any existing dashboard request
    if (activeRequests['dashboard']) {
        activeRequests['dashboard'].abort();
    }

    isLoadingDashboard = true;

    // Create new abort controller for this request
    const abortController = new AbortController();
    activeRequests['dashboard'] = abortController;

    // Set timeout to prevent hanging
    const timeoutId = setTimeout(() => {
        if (!abortController.signal.aborted) {
            abortController.abort();
            isLoadingDashboard = false;
            // Force clear loading state
            const contentBody = document.getElementById('contentBody');
            if (contentBody && contentBody.innerHTML.includes('Loading')) {
                contentBody.innerHTML = '<div class="error">Dashboard loading timeout. Please refresh the page.</div>';
            }
        }
    }, 15000); // 15 second timeout (reduced from 30)


    // Add cache-busting parameter to prevent stale data
    const cacheBuster = new Date().getTime();
    const dashStartDate = document.getElementById('dashStartDate')?.value || '';
    const dashEndDate = document.getElementById('dashEndDate')?.value || '';
    let dashUrl = `api/dashboard.php?_=${cacheBuster}`;
    if (dashStartDate) dashUrl += `&start_date=${encodeURIComponent(dashStartDate)}`;
    if (dashEndDate)   dashUrl += `&end_date=${encodeURIComponent(dashEndDate)}`;
    fetch(dashUrl, {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json',
            'Cache-Control': 'no-cache'
        },
        credentials: 'same-origin',
        signal: abortController.signal,
        cache: 'no-store'
    })
        .then(async res => {
            clearTimeout(timeoutId);
            const text = await res.text();
            if (!res.ok) {
                throw new Error(`HTTP ${res.status}: ${text}`);
            }
            try {
                return JSON.parse(text);
            } catch (e) {
                throw new Error('Invalid JSON response: ' + text.substring(0, 100));
            }
        })
        .then(data => {
            // Check if request was cancelled
            if (abortController.signal.aborted) {
                return;
            }

            delete activeRequests['dashboard'];
            isLoadingDashboard = false;

            const contentBody = document.getElementById('contentBody');
            if (!contentBody) return;

            // Only update if we're still on the dashboard section
            if (currentSection !== 'dashboard') {
                return;
            }

            if (data && data.success) {
                if (window.OfflineState && typeof window.OfflineState.recordOnlineSuccess === 'function') {
                    window.OfflineState.recordOnlineSuccess('dashboard', { source: 'loadDashboard' });
                }
                renderDashboard(data.data);
            } else {
                contentBody.innerHTML =
                    '<div class="error">Failed to load dashboard data: ' + (data?.message || 'Unknown error') + '</div>';
            }
        })
        .catch(err => {
            clearTimeout(timeoutId);
            delete activeRequests['dashboard'];
            isLoadingDashboard = false;

            // Don't show error if request was aborted (user navigated away)
            if (err.name === 'AbortError') {
                return;
            }

            console.error('Dashboard error:', err);
            const contentBody = document.getElementById('contentBody');
            if (contentBody && currentSection === 'dashboard') {
                if (!Utils.isOnline()) {
                    Utils.renderOfflineNotice(
                        contentBody,
                        'Dashboard offline',
                        'A cached dashboard snapshot was not available. Reconnect to refresh the live dashboard.'
                    );
                } else {
                    contentBody.innerHTML =
                        '<div class="error">Error loading dashboard: ' + err.message + '</div>';
                }
            }
        });
}

function renderDashboard(data) {
    data = data || {};
    const financial = data.financial || {};
    const currentMonth = financial.current_month || {};
    const financialToday = financial.today || {};
    const allTime = financial.all_time || {};
    const operations = data.operations || {};
    const men = data.men || { stats: { total: 0, active: 0 }, recent: [] };
    const women = data.women || { stats: { total: 0, active: 0 }, recent: [] };
    const total = data.total || { members: 0, active: 0 };
    const memberGrowthSeries = data.member_growth || [];
    const revenueTrendSeries = data.revenue_trend || [];
    const attendanceTrendSeries = data.attendance_trend || [];
    const expenseTrendSeries = data.expense_trend || [];
    const duesTrendSeries = data.dues_trend || [];
    const attendanceHeatmap = data.attendance_heatmap || [];
    const peakHoursToday = data.peak_hours_today || [];
    const expenseBreakdown = data.expense_breakdown || [];

    const chartTabs = [
        {
            key: 'revenue-trend',
            label: 'Revenue Trend',
            subtitle: 'Recent collections trend',
            chartId: 'dashboardRevenueChart',
            type: 'line',
            series: revenueTrendSeries,
            color: '#0369a1'
        },
        {
            key: 'attendance-trend',
            label: 'Attendance Trend',
            subtitle: 'Recent check-in trend',
            chartId: 'dashboardAttendanceChart',
            type: 'line',
            series: attendanceTrendSeries,
            color: '#7c3aed'
        },
        {
            key: 'expense-trend',
            label: 'Expense Trend',
            subtitle: 'Recent spending trend',
            chartId: 'dashboardExpenseChart',
            type: 'line',
            series: expenseTrendSeries,
            color: '#dc2626'
        },
        {
            key: 'profit-trend',
            label: 'Profit Trend',
            subtitle: 'Revenue minus expenses',
            chartId: 'dashboardProfitChart',
            type: 'line',
            series: revenueTrendSeries.map((item, idx) => ({ label: item.label, total: (Number(item.total) || 0) - (Number(expenseTrendSeries[idx]?.total) || 0) })),
            color: '#b45309'
        },
        {
            key: 'member-growth',
            label: 'Member Growth',
            subtitle: 'Monthly join trend',
            chartId: 'dashboardMembersChart',
            type: 'line',
            series: memberGrowthSeries,
            color: '#166534'
        },
        {
            key: 'member-dues',
            label: 'Member Dues',
            subtitle: 'Outstanding dues over time',
            chartId: 'dashboardDuesChart',
            type: 'line',
            series: duesTrendSeries,
            color: '#dc2626'
        },
        {
            key: 'daily-revenue',
            label: 'Daily Revenue',
            subtitle: 'Last 30 days collections',
            chartId: 'dashboardDailyRevenueChart',
            type: 'bar',
            series: revenueTrendSeries,
            color: '#166534'
        },
        {
            key: 'attendance-heatmap',
            label: 'Attendance Heatmap',
            subtitle: 'Daily check-ins this month',
            chartId: 'dashboardHeatmapChart',
            type: 'heatmap',
            series: attendanceHeatmap,
            color: '#166534'
        },
        {
            key: 'peak-hours',
            label: 'Peak Hours',
            subtitle: 'Busiest hours today',
            chartId: 'dashboardPeakHoursChart',
            type: 'bar',
            series: peakHoursToday,
            color: '#0369a1'
        },
        {
            key: 'expense-breakdown',
            label: 'Expense Breakdown',
            subtitle: 'This month by category',
            chartId: 'dashboardExpensePieChart',
            type: 'pie',
            series: expenseBreakdown,
            color: '#b45309'
        }
    ];

    const activeChartTab = chartTabs.some(chart => chart.key === window.dashboardUiState?.chartTab)
        ? window.dashboardUiState.chartTab
        : chartTabs[0].key;
    const activeRecentTab = ['men', 'women'].includes(window.dashboardUiState?.recentTab)
        ? window.dashboardUiState.recentTab
        : 'men';

    const offlineBanner = !Utils.isOnline()
        ? `<section class="section-card" style="border-left: 4px solid #f59e0b; background: #fff7ed; margin-bottom: 1rem;"><strong>Offline snapshot</strong><p style="margin-top: 0.4rem;">Cached dashboard data is shown when available. Live refresh is paused until you reconnect.</p></section>`
        : '';

    const html = `
        ${offlineBanner}
        <section class="dashboard-intro-card">
            <div class="dashboard-intro-copy">
                <span class="page-chip">Today</span>
                <h2>Fast front-desk actions</h2>
                <p>Keep the page quiet: use the shortcuts below for daily work, then switch charts one at a time when you need detail.</p>
            </div>
            <div class="dashboard-intro-actions">
                <button class="btn btn-primary quick-action-btn" onclick="switchSection('members'); setTimeout(() => document.getElementById('addMemberBtn')?.click(), 150);">Add New Member</button>
                <button class="btn btn-success quick-action-btn" onclick="switchSection('payments'); setTimeout(() => document.getElementById('addPaymentBtn')?.click(), 150);">Take Payment</button>
                <button class="btn btn-warning quick-action-btn" onclick="switchSection('due-fees')">Open Due List</button>
                <button class="btn btn-secondary quick-action-btn" onclick="switchSection('attendance')">Check In / Out</button>
            </div>
        </section>

        <div class="dashboard-overview">
            <div class="dashboard-stats dashboard-stats--compact">
                <div class="stat-card">
                    <h3>Total Members</h3>
                    <p class="stat-value">${total.members || 0}</p>
                </div>
                <div class="stat-card">
                    <h3>Active Members</h3>
                    <p class="stat-value">${total.active || 0}</p>
                </div>
                <div class="stat-card">
                    <h3>Checked In Now</h3>
                    <p class="stat-value">${operations.checked_in_now || 0}</p>
                    <small>Active sessions right now</small>
                </div>
                <div class="stat-card">
                    <h3>Overdue Members</h3>
                    <p class="stat-value">${operations.overdue || 0}</p>
                    <small>Due today: ${operations.due_today || 0}</small>
                </div>
            </div>

            <div class="dashboard-toolbar">
                <div class="dashboard-toolbar__filters">
                    <label for="dashStartDate">Financial period</label>
                    <div class="dashboard-date-range">
                        <input type="date" id="dashStartDate" value="${financial.date_range?.start || ''}" onchange="loadDashboard()">
                        <span>to</span>
                        <input type="date" id="dashEndDate" value="${financial.date_range?.end || ''}" onchange="loadDashboard()">
                        <button class="btn btn-secondary btn-sm" onclick="document.getElementById('dashStartDate').value='';document.getElementById('dashEndDate').value='';loadDashboard();">Reset</button>
                    </div>
                </div>
                ${isAdminUser() ? `
                    <div class="dashboard-toolbar__actions">
                        <button class="btn btn-secondary btn-sm" onclick="forceOpenGate('checkin')">Open Entry Gate</button>
                        <button class="btn btn-secondary btn-sm" onclick="forceOpenGate('checkout')">Open Exit Gate</button>
                    </div>
                ` : ''}
            </div>

            <div class="dashboard-stats dashboard-stats--financial">
                <div class="stat-card stat-card--accent-green">
                    <h3>Money Received</h3>
                    <p class="stat-value">${Utils.formatCurrency(currentMonth.revenue || 0)}</p>
                    <small>${financial.date_range?.start || ''}${financial.date_range?.end ? ' → ' + financial.date_range.end : ''}</small>
                </div>
                <div class="stat-card stat-card--accent-amber">
                    <h3>Money Spent</h3>
                    <p class="stat-value">${Utils.formatCurrency(currentMonth.expenses || 0)}</p>
                    <small>${financial.date_range?.start || ''}${financial.date_range?.end ? ' → ' + financial.date_range.end : ''}</small>
                </div>
                <div class="stat-card stat-card--accent-blue">
                    <h3>Profit</h3>
                    <p class="stat-value">${Utils.formatCurrency(currentMonth.profit || 0)}</p>
                    <small>${(currentMonth.profit || 0) >= 0 ? '✅ Profit' : '❌ Loss'}</small>
                </div>
            </div>
        </div>

        ${renderDashboardChartHub(chartTabs, activeChartTab)}
        ${renderDashboardRecentMembers(men, women, activeRecentTab)}
    `;

    document.getElementById('contentBody').innerHTML = html;

    if (!document.getElementById('changePasswordModal')) {
        const modalEl = document.createElement('div');
        modalEl.id = 'changePasswordModal';
        modalEl.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(243,247,244,0.92);z-index:9999;align-items:center;justify-content:center;';
        modalEl.innerHTML = `
            <div style="background:var(--bg-secondary);border-radius:12px;padding:2rem;width:100%;max-width:420px;box-shadow:0 8px 32px rgba(20,41,28,0.12);border:1px solid #d1fae5;">
                <h2 style="margin-top:0;">🔑 Change Password</h2>
                <div id="changePwError" style="display:none;color:#dc2626;margin-bottom:1rem;padding:0.5rem;background:#fef2f2;border-radius:6px;"></div>
                <label style="display:block;margin-bottom:0.4rem;font-weight:600;">Current Password</label>
                <input type="password" id="cpCurrent" placeholder="Current password" style="width:100%;padding:0.6rem;border:1px solid #d1d5db;border-radius:6px;margin-bottom:1rem;box-sizing:border-box;">
                <label style="display:block;margin-bottom:0.4rem;font-weight:600;">New Password</label>
                <input type="password" id="cpNew" placeholder="At least 8 characters" style="width:100%;padding:0.6rem;border:1px solid #d1d5db;border-radius:6px;margin-bottom:1rem;box-sizing:border-box;">
                <label style="display:block;margin-bottom:0.4rem;font-weight:600;">Confirm New Password</label>
                <input type="password" id="cpConfirm" placeholder="Repeat new password" style="width:100%;padding:0.6rem;border:1px solid #d1d5db;border-radius:6px;margin-bottom:1.5rem;box-sizing:border-box;">
                <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                    <button class="btn btn-secondary" onclick="document.getElementById('changePasswordModal').style.display='none';">Cancel</button>
                    <button class="btn btn-primary" onclick="submitChangePassword()">Save</button>
                </div>
            </div>`;
        document.body.appendChild(modalEl);
    }

    setDashboardChartTab(activeChartTab);
    setDashboardRecentTab(activeRecentTab);
}


window.dashboardUiState = window.dashboardUiState || {
    chartTab: 'revenue-trend',
    recentTab: 'men'
};

function setDashboardChartTab(tabKey) {
    window.dashboardUiState = window.dashboardUiState || {};
    window.dashboardUiState.chartTab = tabKey;

    document.querySelectorAll('[data-dashboard-chart-tab]').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.dashboardChartTab === tabKey);
        btn.setAttribute('aria-selected', btn.dataset.dashboardChartTab === tabKey ? 'true' : 'false');
    });
    renderDashboardActiveChart(tabKey);
}

function setDashboardRecentTab(tabKey) {
    window.dashboardUiState = window.dashboardUiState || {};
    window.dashboardUiState.recentTab = tabKey;

    document.querySelectorAll('[data-dashboard-recent-tab]').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.dashboardRecentTab === tabKey);
    });

    renderDashboardRecentViewport(tabKey);
}

function getDashboardChartConfig(tabKey, chartTabs = window.dashboardChartTabs || []) {
    return chartTabs.find(chart => chart.key === tabKey) || chartTabs[0] || null;
}

function renderDashboardActiveChart(tabKey = window.dashboardUiState?.chartTab) {
    const chartTabs = window.dashboardChartTabs || [];
    const config = getDashboardChartConfig(tabKey, chartTabs);
    const viewport = document.getElementById('dashboardChartViewport');
    if (!viewport || !config) return;

    const empty = !config.series || !config.series.length;
    const chartCardClass = config.type === 'pie' ? 'chart-card chart-card--featured chart-card--pie' : 'chart-card chart-card--featured';
    viewport.innerHTML = `
        <section class="dashboard-chart-panel is-active" data-dashboard-chart-panel="${config.key}">
            <div class="${chartCardClass}">
                <div class="chart-card-header">
                    <div>
                        <h3>${config.label}</h3>
                        <small>${config.subtitle}</small>
                    </div>
                    <span class="chart-card-pill">${config.type === 'heatmap' ? 'Calendar view' : config.type === 'pie' ? 'Breakdown' : 'Trend view'}</span>
                </div>
                <div class="dashboard-chart-stage">
                    <canvas id="${config.chartId}" width="720" height="280"></canvas>
                    ${empty ? '<div class="activity-muted chart-empty-state">No chart data available yet.</div>' : ''}
                </div>
            </div>
        </section>
    `;

    setTimeout(() => {
        if (config.type === 'heatmap') {
            renderAttendanceHeatmap(config.chartId, config.series || []);
        } else if (config.type === 'pie') {
            renderPieChart(config.chartId, config.series || []);
        } else if (config.type === 'bar') {
            renderSimpleBarChart(config.chartId, config.series || [], config.color);
        } else {
            renderSimpleLineChart(config.chartId, config.series || [], config.color);
        }
    }, 0);
}

function renderDashboardRecentViewport(tabKey = window.dashboardUiState?.recentTab) {
    const viewport = document.getElementById('dashboardRecentViewport');
    const recentData = window.dashboardRecentData || { men: { recent: [] }, women: { recent: [] } };
    if (!viewport) return;

    const activeRows = tabKey === 'women' ? (recentData.women?.recent || []) : (recentData.men?.recent || []);
    viewport.innerHTML = `
        <div class="recent-table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Phone</th>
                        <th>Join Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    ${activeRows.length > 0 ? activeRows.map((m, idx) => `
                        <tr>
                            <td>${idx + 1}</td>
                            <td>${m.member_code}</td>
                            <td>${m.name}</td>
                            <td>${m.phone}</td>
                            <td>${Utils.formatDate(m.join_date)}</td>
                            <td><span class="status-badge status-${m.status || 'unknown'}">${m.status || 'unknown'}</span></td>
                        </tr>
                    `).join('') : '<tr><td colspan="6" style="text-align:center;padding:2rem;">No members yet</td></tr>'}
                </tbody>
            </table>
        </div>
    `;
}

function renderDashboardChartHub(chartTabs = [], activeTab = '') {
    if (!chartTabs.length) return '';

    window.dashboardChartTabs = chartTabs;

    return `
        <section class="dashboard-shell dashboard-chart-hub">
            <div class="dashboard-shell__header">
                <div>
                    <span class="page-chip">Analytics</span>
                    <h2>Chart menu</h2>
                    <p>Select one chart to keep the dashboard focused and readable.</p>
                </div>
                <div class="dashboard-chart-tabs" role="tablist" aria-label="Dashboard charts">
                    ${chartTabs.map(chart => `
                        <button
                            type="button"
                            class="dashboard-chart-tab ${activeTab === chart.key ? 'active' : ''}"
                            data-dashboard-chart-tab="${chart.key}"
                            role="tab"
                            aria-selected="${activeTab === chart.key ? 'true' : 'false'}"
                            onclick="setDashboardChartTab('${chart.key}')">
                            ${chart.label}
                        </button>
                    `).join('')}
                </div>
            </div>
            <div class="dashboard-chart-viewport" id="dashboardChartViewport" aria-live="polite">
                ${renderDashboardChartPanel(getDashboardChartConfig(activeTab, chartTabs))}
            </div>
        </section>
    `;
}

function renderDashboardRecentMembers(men = { recent: [] }, women = { recent: [] }, activeTab = 'men') {
    window.dashboardRecentData = { men, women };

    return `
        <section class="dashboard-shell dashboard-recent-shell">
            <div class="dashboard-shell__header dashboard-shell__header--stacked">
                <div>
                    <span class="page-chip">Recent members</span>
                    <h2>Newest signups</h2>
                    <p>Switch between men and women without loading another section.</p>
                </div>
                <div class="gender-tabs dashboard-recent-tabs" role="tablist" aria-label="Recent members">
                    <button type="button" class="gender-tab ${activeTab === 'men' ? 'active' : ''}" data-dashboard-recent-tab="men" onclick="setDashboardRecentTab('men')">Men</button>
                    <button type="button" class="gender-tab ${activeTab === 'women' ? 'active' : ''}" data-dashboard-recent-tab="women" onclick="setDashboardRecentTab('women')">Women</button>
                </div>
            </div>
            <div class="dashboard-recent-viewport" id="dashboardRecentViewport" aria-live="polite"></div>
        </section>
    `;
}

function renderDashboardChartPanel(config) {
    if (!config) {
        return '<div class="empty-state">No chart data available yet.</div>';
    }

    const empty = !config.series || !config.series.length;
    const chartCardClass = config.type === 'pie' ? 'chart-card chart-card--featured chart-card--pie' : 'chart-card chart-card--featured';

    return `
        <section class="dashboard-chart-panel is-active" data-dashboard-chart-panel="${config.key}">
            <div class="${chartCardClass}">
                <div class="chart-card-header">
                    <div>
                        <h3>${config.label}</h3>
                        <small>${config.subtitle}</small>
                    </div>
                    <span class="chart-card-pill">${config.type === 'heatmap' ? 'Calendar view' : config.type === 'pie' ? 'Breakdown' : 'Trend view'}</span>
                </div>
                <div class="dashboard-chart-stage">
                    <canvas id="${config.chartId}" width="720" height="280"></canvas>
                    ${empty ? '<div class="activity-muted chart-empty-state">No chart data available yet.</div>' : ''}
                </div>
            </div>
        </section>
    `;
}

function renderDashboardCharts(configs = []) {
    window.dashboardChartTabs = configs;
    const activeTab = window.dashboardUiState?.chartTab || configs[0]?.key;
    renderDashboardActiveChart(activeTab);
}


function showChangePasswordModal() {
    const modal = document.getElementById('changePasswordModal');
    if (!modal) return;
    document.getElementById('cpCurrent').value = '';
    document.getElementById('cpNew').value = '';
    document.getElementById('cpConfirm').value = '';
    const err = document.getElementById('changePwError');
    if (err) { err.style.display = 'none'; err.textContent = ''; }
    modal.style.display = 'flex';
}

async function submitChangePassword() {
    const current = document.getElementById('cpCurrent')?.value || '';
    const newPw = document.getElementById('cpNew')?.value || '';
    const confirm = document.getElementById('cpConfirm')?.value || '';
    const errEl = document.getElementById('changePwError');
    const showErr = msg => { if (errEl) { errEl.textContent = msg; errEl.style.display = 'block'; } };

    if (!current || !newPw || !confirm) return showErr('All fields are required.');
    if (newPw.length < 8) return showErr('New password must be at least 8 characters.');
    if (newPw !== confirm) return showErr('New passwords do not match.');

    try {
        const data = await Utils.apiPost('api/auth.php?action=change_password', {
            current_password: current,
            new_password: newPw,
            confirm_password: confirm
        });
        if (data.success) {
            document.getElementById('changePasswordModal').style.display = 'none';
            Utils.showNotification('Password changed successfully.', 'success');
        } else {
            showErr(data.message || 'Failed to change password.');
        }
    } catch (e) {
        showErr('Network error. Please try again.');
    }
}

function forceOpenGate(gateType) {
    if (!requireAdminAccess('force open the gate')) {
        return;
    }

    if (!confirm(`Are you sure you want to force open the ${gateType === 'checkin' ? 'Check-In' : 'Check-Out'} gate?`)) {
        return;
    }

    fetch(`api/gate.php?type=force_open&gate=${gateType}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                Utils.showNotification(`Force open command sent for ${gateType === 'checkin' ? 'Check-In' : 'Check-Out'} gate`, 'success');
            } else {
                Utils.showNotification(data.message || 'Failed to send force open command', 'error');
            }
        })
        .catch(err => {
            console.error('Force open error:', err);
            Utils.showNotification('Error sending force open command', 'error');
        });
}

function getMemberStatusFromDue(member = {}) {
    const totalDue = Number(member.total_due_amount || 0);
    const monthlyFee = Number(member.monthly_fee || 0);
    const joinDate = member.join_date || member.created_at || null;
    const dueDate = member.next_fee_due_date || joinDate;

    if (totalDue <= 0) return 'active';
    if (monthlyFee > 0 && totalDue >= (monthlyFee * 2) - 0.01) return 'inactive';

    if (dueDate) {
        const due = new Date(dueDate);
        const now = new Date();
        const threshold = new Date(now.getFullYear(), now.getMonth() - 2, now.getDate());
        if (!Number.isNaN(due.getTime()) && due <= threshold) {
            return 'inactive';
        }
    }

    return 'active';
}

function normalizeMemberStatus(member = {}) {
    const calculatedStatus = getMemberStatusFromDue(member);
    return {
        ...member,
        status: ['active', 'inactive'].includes(member.status) ? member.status : calculatedStatus,
        calculated_status: calculatedStatus
    };
}

function setCurrentGender(gender) {
    if (!['men', 'women'].includes(gender)) return;
    currentGender = gender;
    document.querySelectorAll('.gender-tab').forEach(t => t.classList.remove('active'));
    document.querySelector(`.gender-tab[data-gender="${gender}"]`)?.classList.add('active');
}

function getCachedMemberProfileSnapshot(memberCode) {
    const normalizedCode = String(memberCode || '').trim().toLowerCase();
    if (!normalizedCode) return null;

    const offlineState = window.OfflineState;
    if (!offlineState || typeof offlineState.getSnapshot !== 'function') return null;

    const menSnapshot = offlineState.getSnapshot('member-profile', `men:${normalizedCode}`);
    if (menSnapshot) return menSnapshot.payload || menSnapshot;

    const womenSnapshot = offlineState.getSnapshot('member-profile', `women:${normalizedCode}`);
    if (womenSnapshot) return womenSnapshot.payload || womenSnapshot;

    return null;
}

async function fetchMemberByCodeForGender(memberCode, gender) {
    const res = await fetch(`api/members.php?action=getByCode&code=${encodeURIComponent(memberCode)}&gender=${gender}`);
    const text = await res.text();

    if (!text) {
        return { success: false, message: 'Empty response from server' };
    }

    try {
        return JSON.parse(text);
    } catch (error) {
        return { success: false, message: 'Invalid JSON response' };
    }
}

async function lookupMemberByCodeAcrossGenders(memberCode) {
    const normalizedCode = String(memberCode || '').trim();
    if (!normalizedCode) {
        return { success: false, message: 'Please enter member code.' };
    }

    if (!Utils.isOnline()) {
        return {
            success: false,
            offline: true,
            message: 'Reconnect to look up live member records safely. Existing-member edits still need a live record.'
        };
    }

    for (const gender of ['men', 'women']) {
        try {
            const data = await fetchMemberByCodeForGender(normalizedCode, gender);
            if (data && data.success && data.data) {
                return { success: true, data: data.data, gender };
            }
        } catch (error) {
            console.error(`Member lookup error for ${gender}:`, error);
        }
    }

    return { success: false, message: 'Member not found.' };
}

function loadMembers() {
    if (!Utils.isOnline()) {
        const offlineNotice = window.OfflineState && typeof window.OfflineState.renderCapabilityNotice === 'function'
            ? window.OfflineState.renderCapabilityNotice('members', {
                title: 'Members section offline',
                body: 'Member browsing and existing-member edits stay live-only. You can still open a blank add-member form and queue a text-only create locally.'
            })
            : '';

        document.getElementById('contentBody').innerHTML = `
            <div class="members-section">
                ${renderSectionGuideCard({
                    chip: 'Members Help',
                    title: 'Add, search, or update a member',
                    description: 'If someone is standing at the desk, first search by code, name, or phone. If not found, add them as a new member.',
                    steps: [
                        'Use the search box to find an existing member.',
                        'Use Active only or Inactive only if the list looks too long.',
                        'Use Add New Member to queue a new member locally when you are offline.'
                    ]
                })}
                ${offlineNotice}
                ${isAdminUser() ? '<div style="margin-top:1rem;"><button class="btn btn-primary" onclick="showAddMemberForm()">Add New Member</button></div>' : ''}
            </div>
        `;
        return;
    }

    const html = `
        <div class="members-section">
            ${renderSectionGuideCard({
                chip: 'Members Help',
                title: 'Add, search, or update a member',
                description: 'If someone is standing at the desk, first search by code, name, or phone. If not found, add them as a new member.',
                steps: [
                    'Use the search box to find an existing member.',
                    'Use Active only or Inactive only if the list looks too long.',
                    'Click Take Fee if you want to update dues quickly.'
                ]
            })}
            <div class="section-header">
                <div class="gender-tabs">
                    <button class="gender-tab ${currentGender === 'men' ? 'active' : ''}" data-gender="men">Men Members</button>
                    <button class="gender-tab ${currentGender === 'women' ? 'active' : ''}" data-gender="women">Women Members</button>
                </div>
                <div class="section-actions">
                    <input type="text" id="memberSearch" placeholder="Search by code, name, phone, email, or card" class="search-input">
                    <button class="btn ${memberStatusFilter === 'active' ? 'btn-primary' : 'btn-secondary'}" id="activeOnlyBtn">Active only</button>
                    <button class="btn ${memberStatusFilter === 'inactive' ? 'btn-primary' : 'btn-secondary'}" id="inactiveOnlyBtn">Inactive only</button>
                    <button class="btn ${memberStatusFilter === null ? 'btn-primary' : 'btn-secondary'}" id="allMembersBtn">Show all</button>
                    ${isAdminUser() ? '<button class="btn btn-primary" id="addMemberBtn">Add New Member</button>' : ''}
                </div>
            </div>
            <div style="margin-bottom:1rem;">
                <input type="text" id="crossGenderSearch" placeholder="🔍 Search across ALL members (both genders)…" class="search-input" style="width:100%;max-width:480px;">
                <div id="crossGenderResults" style="display:none;margin-top:0.5rem;background:var(--bg-secondary);border:1px solid #d1fae5;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(20,41,28,0.08);"></div>
            </div>
            <div id="membersAnalyticsContainer" style="margin-bottom:1.5rem;"></div>
            <div id="membersTableContainer"></div>
        </div>
    `;
    document.getElementById('contentBody').innerHTML = html;

    // Setup gender tabs
    document.querySelectorAll('.gender-tab').forEach(tab => {
        tab.addEventListener('click', function () {
            currentGender = this.dataset.gender;
            document.querySelectorAll('.gender-tab').forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            loadMembersTable();
        });
    });

    // Setup search
    const searchInput = document.getElementById('memberSearch');
    if (searchInput) {
        searchInput.addEventListener('input', Utils.debounce(function () {
            loadMembersTable();
        }, 300));
    }

    // Setup cross-gender search
    const crossSearch = document.getElementById('crossGenderSearch');
    const crossResults = document.getElementById('crossGenderResults');
    if (crossSearch && crossResults) {
        crossSearch.addEventListener('input', Utils.debounce(function (event) {
            const q = (event?.target?.value || '').trim();
            if (q.length < 2) { crossResults.style.display = 'none'; crossResults.innerHTML = ''; return; }
            if (!Utils.isOnline()) {
                crossResults.innerHTML = '<div style="padding:0.75rem 1rem;color:#b45309;">Reconnect to use cross-gender lookup safely.</div>';
                crossResults.style.display = 'block';
                return;
            }
            fetch(`api/members.php?action=search_all&q=${encodeURIComponent(q)}`)
                .then(r => r.json())
                .then(res => {
                    if (!res.success || !res.data.length) {
                        crossResults.innerHTML = '<div style="padding:0.75rem 1rem;color:#6b7280;">No members found.</div>';
                    } else {
                        crossResults.innerHTML = res.data.map(m => `
                            <div style="padding:0.6rem 1rem;border-bottom:1px solid #f0fdf4;display:flex;gap:1rem;align-items:center;flex-wrap:wrap;cursor:pointer;"
                                 onclick="currentGender='${m.gender}';document.querySelector('.gender-tab[data-gender=\\'${m.gender}\\']')?.click();document.getElementById('memberSearch').value='${m.member_code.replace(/'/g,"\\'")}';loadMembersTable();">
                                <span style="font-weight:600;">${m.name}</span>
                                <span style="color:#166534;font-size:0.85rem;">${m.member_code}</span>
                                <span style="color:#6b7280;font-size:0.85rem;">${m.phone || ''}</span>
                                <span class="status-badge status-${m.status}">${m.status}</span>
                                <span style="background:${m.gender==='men'?'#dbeafe':'#fce7f3'};color:${m.gender==='men'?'#1d4ed8':'#be185d'};border-radius:999px;padding:0.1rem 0.55rem;font-size:0.78rem;">${m.gender}</span>
                                ${parseFloat(m.total_due_amount||0)>0?`<span style="color:#dc2626;font-size:0.83rem;">Due: ${Utils.formatCurrency(m.total_due_amount)}</span>`:''}
                            </div>`).join('');
                    }
                    crossResults.style.display = 'block';
                })
                .catch(() => { crossResults.style.display = 'none'; });
        }, 300));
        // Hide on outside click
        document.addEventListener('click', function hideCross(e) {
            if (!crossSearch.contains(e.target) && !crossResults.contains(e.target)) {
                crossResults.style.display = 'none';
            }
        });
    }

    // Setup add button
    const addBtn = document.getElementById('addMemberBtn');
    if (addBtn) {
        addBtn.addEventListener('click', showAddMemberForm);
    }

    // Setup status filter buttons
    const activeOnlyBtn = document.getElementById('activeOnlyBtn');
    const allMembersBtn = document.getElementById('allMembersBtn');

    if (activeOnlyBtn) {
        activeOnlyBtn.addEventListener('click', function () {
            memberStatusFilter = 'active';
            activeOnlyBtn.classList.remove('btn-secondary');
            activeOnlyBtn.classList.add('btn-primary');
            allMembersBtn.classList.remove('btn-primary');
            allMembersBtn.classList.add('btn-secondary');
            loadMembersTable(1);
        });
    }

    if (allMembersBtn) {
        allMembersBtn.addEventListener('click', function () {
            memberStatusFilter = null;
            updateFilterButtons(this);
            loadMembersTable(1);
        });
    }

    // Add Inactive Only Button Logic
    const inactiveOnlyBtn = document.getElementById('inactiveOnlyBtn');
    if (inactiveOnlyBtn) {
        inactiveOnlyBtn.addEventListener('click', function () {
            memberStatusFilter = 'inactive';
            updateFilterButtons(this);
            loadMembersTable(1);
        });
    }

    loadMembersAnalytics();
    loadMembersTable(1); // Initial load of the members table
}

function loadMembersAnalytics() {
    const container = document.getElementById('membersAnalyticsContainer');
    if (!container) return;

    if (!Utils.isOnline()) {
        Utils.renderOfflineNotice(
            container,
            'Members analytics offline',
            'Reconnect to refresh the member charts safely.'
        );
        return;
    }

    fetch('api/reports.php?action=members')
        .then(res => res.json())
        .then(result => {
            if (!result.success) throw new Error(result.message || 'Failed to load members analytics');
            const data = result.data || {};
            container.innerHTML = `
                <div class="activity-analytics-grid">
                    ${renderAnalyticsBlock('Monthly Growth', 'New member trend', 'membersPageGrowthChart', data.charts?.monthly_growth || [], 'line', '#166534')}
                    ${renderAnalyticsBlock('Gender Split', 'Men vs women', 'membersPageGenderChart', data.charts?.gender_split || [], 'bar', '#0369a1')}
                    ${renderAnalyticsBlock('Status Split', 'Active and inactive overview', 'membersPageStatusChart', data.charts?.active_split || [], 'bar', '#b45309')}
                </div>
            `;
            renderReportCharts([
                { id: 'membersPageGrowthChart', type: 'line', series: data.charts?.monthly_growth || [], color: '#166534' },
                { id: 'membersPageGenderChart', type: 'bar', series: data.charts?.gender_split || [], color: '#0369a1' },
                { id: 'membersPageStatusChart', type: 'bar', series: data.charts?.active_split || [], color: '#b45309' }
            ]);
        })
        .catch(err => {
            container.innerHTML = `<div class="error">${err.message}</div>`;
        });
}

// End of DOMContentLoaded setup


function updateFilterButtons(activeBtn) {
    ['activeOnlyBtn', 'allMembersBtn', 'inactiveOnlyBtn'].forEach(id => {
        const btn = document.getElementById(id);
        if (btn) {
            if (btn === activeBtn) {
                btn.classList.remove('btn-secondary');
                btn.classList.add('btn-primary');
            } else {
                btn.classList.remove('btn-primary');
                btn.classList.add('btn-secondary');
            }
        }

    });
}


function loadMembersTable(page = 1) {
    // Ensure page is a number
    page = parseInt(page) || 1;

    if (!Utils.isOnline()) {
        Utils.renderOfflineNotice(
            '#membersTableContainer',
            'Members list offline',
            'The member roster, lookup, and edit actions need a live connection. Reconnect to load them safely.'
        );
        return;
    }

    const search = document.getElementById('memberSearch')?.value || '';
    const limit = 20;
    const statusParam = memberStatusFilter ? `&status=${memberStatusFilter}` : '';

    fetch(`api/members.php?action=list&gender=${currentGender}&page=${page}&limit=${limit}&search=${encodeURIComponent(search)}${statusParam}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                if (window.OfflineState && typeof window.OfflineState.recordOnlineSuccess === 'function') {
                    window.OfflineState.recordOnlineSuccess('members', { source: 'loadMembersTable' });
                }
                const normalizedMembers = (data.data || []).map(normalizeMemberStatus);
                renderMembersTable(normalizedMembers, data.pagination || { page: 1, pages: 1, limit });
            } else {
                document.getElementById('membersTableContainer').innerHTML =
                    '<div class="error">Failed to load members</div>';
            }
        })
        .catch(err => {
            console.error('Members error:', err);
            document.getElementById('membersTableContainer').innerHTML =
                '<div class="error">Error loading members</div>';
        });
}

function renderMembersTable(members, pagination) {
    const currentPage = parseInt(pagination.page) || 1;
    const totalPages = parseInt(pagination.pages) || 1;
    const limit = parseInt(pagination.limit) || 20;
    const startIndex = (currentPage - 1) * limit;

    const html = `
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>Join Date</th>
                        <th>Due Amount</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    ${members.length > 0 ? members.map((m, idx) => `
                        <tr>
                            <td data-label="#">${startIndex + idx + 1}</td>
                            <td data-label="Code">${m.member_code}</td>
                            <td data-label="Name">${m.name}</td>
                            <td data-label="Phone">${m.phone}</td>
                            <td data-label="Email">${m.email || 'N/A'}</td>
                            <td data-label="Join Date">${Utils.formatDate(m.join_date)}</td>
                            <td data-label="Due Amount">${m.total_due_amount > 0 ? `<span style="color: red; font-weight: bold;">${Utils.formatCurrency(m.total_due_amount)}</span>` : '<span style="color: green;">No Due</span>'}</td>
                            <td data-label="Status"><span class="status-badge status-${m.calculated_status || m.status}">${m.calculated_status || m.status}</span></td>
                            <td data-label="Actions">
                                <button class="btn btn-sm btn-secondary" onclick="openMemberProfile('${m.member_code}', '${currentGender}')">Open</button>
                                ${isAdminUser() ? `
                                    <button class="btn btn-sm btn-primary" onclick="editMember(${m.id})">Edit</button>
                                    <button class="btn btn-sm btn-success" onclick="updateFee(${m.id}, '${m.member_code}')">Take Fee</button>
                                    <button class="btn btn-sm btn-danger" onclick="deleteMember(${m.id})">Delete</button>
                                ` : ''}
                            </td>
                        </tr>
                    `).join('') : `
                        <tr>
                            <td colspan="9">
                                <div class="empty-state">
                                    <strong>No members found</strong>
                                    Try changing the search or filter. If this is a new person, click <em>Add New Member</em>.
                                </div>
                            </td>
                        </tr>
                    `}
                </tbody>
            </table>
        </div>
        ${totalPages > 1 ? `
            <div class="pagination" style="margin-top: 1rem; display: flex; justify-content: center; align-items: center; gap: 1rem; flex-wrap: wrap;">
                <button class="btn btn-secondary" ${currentPage === 1 ? 'disabled' : ''} onclick="loadMembersTable(${currentPage - 1})">Previous</button>
                <span>Page</span>
                <input type="number" id="membersPageInput" min="1" max="${totalPages}" value="${currentPage}" style="width: 60px; padding: 0.25rem; text-align: center; border: 1px solid #ddd; border-radius: 4px;" onchange="const page = parseInt(this.value) || 1; if (page >= 1 && page <= ${totalPages}) loadMembersTable(page); else this.value = ${currentPage};" onkeypress="if(event.key === 'Enter') { const page = parseInt(this.value) || 1; if (page >= 1 && page <= ${totalPages}) loadMembersTable(page); else this.value = ${currentPage}; }">
                <span>of ${totalPages}</span>
                <button class="btn btn-secondary" ${currentPage === totalPages ? 'disabled' : ''} onclick="loadMembersTable(${currentPage + 1})">Next</button>
            </div>
        ` : ''}
    `;
    document.getElementById('membersTableContainer').innerHTML = html;
}

function showAddMemberForm() {
    if (!requireAdminAccess('add members')) return;

    const offlineDraftNote = !Utils.isOnline()
        ? '<div class="simple-note" style="border-left: 4px solid #f59e0b; background: #fffbeb;"><strong>Offline save:</strong> New members can queue locally without a fresh photo. Deletions stay online-only.</div>'
        : '';

    const html = `
        <div class="modal" id="memberModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>Add New Member</h2>
                    <button class="modal-close" onclick="closeMemberModal()">&times;</button>
                </div>
                <form id="memberForm" class="modal-body">
                    <input type="hidden" id="memberId" name="id">
                    <input type="hidden" id="memberUpdatedAt" name="expected_updated_at">
                    <input type="hidden" id="existingProfileImage" name="existing_profile_image">
                    <input type="hidden" id="memberResolutionItemId" name="resolution_outbox_item_id">
                    <div class="simple-note"><strong>Tip:</strong> Start with code, name, phone, join date, and monthly fee. Other fields can be filled later.</div>
                    <div id="memberConflictResolutionNote" style="display:none;margin:0.75rem 0;padding:0.75rem 0.9rem;border-left:4px solid #f59e0b;background:#fffbeb;color:#7c2d12;border-radius:6px;"></div>
                    ${offlineDraftNote}
                    <div class="form-group">
                        <label>Member Code / Account No. *</label>
                        <input type="text" id="memberCode" name="member_code" placeholder="Example: M001" required>
                    </div>
                        <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" id="memberName" name="name" placeholder="Enter member name" required>
                    </div>
                    <div class="form-group">
                        <label>Phone *</label>
                        <input type="text" id="phone" name="phone" placeholder="03XXXXXXXXX" required>
                    </div>
                    <div class="form-group">
                        <label>RFID / Membership Card (optional)</label>
                        <div style="display: flex; gap: 10px;">
                            <input type="text" id="rfidUid" name="rfid_uid" placeholder="Scan or type card number" style="flex: 1;">
                            <button type="button" class="btn btn-secondary" onclick="startRFIDScan()" id="scanRfidBtn">
                                <i class="fas fa-wifi"></i> Scan Card
                            </button>
                        </div>
                        <small id="scanStatus">Optional. Use this only if the member has a card for gate entry.</small>
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" id="email" name="email" placeholder="Optional email address">
                    </div>
                    <div class="form-group">
                        <label>Address</label>
                        <textarea id="address" name="address" placeholder="Optional address"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Profile Picture</label>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <input type="file" id="profileImage" name="profile_image" accept="image/*" style="flex: 1;">
                            <button type="button" class="btn btn-secondary" onclick="startCamera()" style="display: flex; align-items: center; gap: 5px;">
                                <i class="fas fa-camera"></i> Take Photo
                            </button>
                        </div>
                        <small>Accepted formats: JPG, PNG, GIF, WebP (Max 5MB)</small>
                        <div id="profileImagePreview" style="margin-top: 10px; display: none;">
                            <img id="previewImg" src="" alt="Preview" style="max-width: 150px; max-height: 150px; border-radius: 5px;">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Join Date *</label>
                            <input type="date" id="joinDate" name="join_date" required>
                        </div>
                        <div class="form-group">
                            <label>Membership Type</label>
                            <select id="membershipType" name="membership_type">
                                <option value="">Loading types…</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Admission Fee</label>
                            <input type="number" step="0.01" id="admissionFee" name="admission_fee" value="0">
                        </div>
                        <div class="form-group">
                            <label>Monthly Fee *</label>
                            <input type="number" step="0.01" id="monthlyFee" name="monthly_fee" value="0">
                        </div>
                        <div class="form-group">
                            <label>Trainer Fee</label>
                            <input type="number" step="0.01" id="trainerFee" name="ptf_fee" value="0">
                        </div>
                        <div class="form-group">
                            <label>Locker Fee</label>
                            <input type="number" step="0.01" id="lockerFee" name="locker_fee" value="0">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Next Fee Due Date</label>
                        <input type="date" id="nextFeeDueDate" name="next_fee_due_date">
                    </div>
                    <div class="form-group">
                        <label>Membership Status (admin only)</label>
                        <select id="status" name="status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                        <small>Only admins can activate a member.</small>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeMemberModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Member</button>
                    </div>
                </form>
            </div>
        </div>
    `;
    document.body.insertAdjacentHTML('beforeend', html);
    populateMembershipTypeOptions();

    const form = document.getElementById('memberForm');
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        saveMember();
    });

    // Profile image preview
    const profileImageInput = document.getElementById('profileImage');
    if (profileImageInput) {
        profileImageInput.addEventListener('change', function (e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function (e) {
                    const preview = document.getElementById('profileImagePreview');
                    const previewImg = document.getElementById('previewImg');
                    previewImg.src = e.target.result;
                    preview.style.display = 'block';
                };
                reader.readAsDataURL(file);
            }
        });
    }
}

function closeMemberModal() {
    const modal = document.getElementById('memberModal');
    if (modal) modal.remove();
}

// Membership Type options = the owner's ACTIVE Packages (Details > Packages).
// No built-in tiers. When editing, the member's stored value is injected as an
// option even if it isn't an active package, so it's never silently dropped.
let _membershipTypeReqSeq = 0;
function populateMembershipTypeOptions(selectedValue) {
    const sel = document.getElementById('membershipType');
    if (!sel) return;
    const chosen = (selectedValue == null ? '' : String(selectedValue)).trim();
    const seq = ++_membershipTypeReqSeq;
    const render = (names) => {
        if (seq !== _membershipTypeReqSeq) return; // a newer open superseded this
        const seen = new Set();
        const list = [];
        (names || []).forEach(n => {
            const name = String(n || '').trim();
            if (name && !seen.has(name)) { seen.add(name); list.push(name); }
        });
        if (chosen && !seen.has(chosen)) list.unshift(chosen);
        sel.innerHTML = '';
        if (list.length === 0) {
            const o = document.createElement('option');
            o.value = '';
            o.textContent = 'No membership types yet — add one in Details';
            sel.appendChild(o);
            return;
        }
        list.forEach(name => {
            const o = document.createElement('option');
            o.value = name;
            o.textContent = name;
            if (name === chosen) o.selected = true;
            sel.appendChild(o);
        });
        if (chosen) sel.value = chosen;
    };
    fetch('api/packages.php?action=list&limit=200')
        .then(res => res.json())
        .then(data => {
            const rows = (data && data.success && Array.isArray(data.data)) ? data.data : [];
            render(rows.filter(p => p && parseInt(p.is_active) !== 0).map(p => p.name));
        })
        .catch(() => render([])); // offline/error: keep at least the current value
}

function saveMember() {
    const profileImageInput = document.getElementById('profileImage');
    const hasImage = profileImageInput && profileImageInput.files.length > 0;
    const memberCodeValue = document.getElementById('memberCode')?.value || '';

    if (hasImage && !Utils.isOnline()) {
        Utils.showNotification('Reconnect before attaching a new photo. Offline member saves are text-only.', 'warning');
        return;
    }

    // If there's an image, upload it first
    if (hasImage) {
        const imageFormData = new FormData();
        imageFormData.append('image', profileImageInput.files[0]);
        imageFormData.append('gender', currentGender);
        imageFormData.append('member_code', memberCodeValue);

        fetch('api/upload-profile.php', {
            method: 'POST',
            body: imageFormData
        })
            .then(res => res.json())
            .then(imageData => {
                if (imageData.success) {
                    saveMemberData(imageData.path);
                } else {
                    Utils.showNotification(imageData.message || 'Failed to upload image', 'error');
                }
            })
            .catch(err => {
                console.error('Image upload error:', err);
                Utils.showNotification('Error uploading image', 'error');
            });
    } else {
        // No image, save member data directly
        const existingImage = document.getElementById('existingProfileImage')?.value || null;
        saveMemberData(existingImage);
    }
}

function saveMemberData(profileImagePath) {
    const formData = {
        id: document.getElementById('memberId').value || null,
        member_code: document.getElementById('memberCode').value,
        name: document.getElementById('memberName').value,
        phone: document.getElementById('phone').value,
        rfid_uid: document.getElementById('rfidUid').value || null,
        email: document.getElementById('email').value || null,
        address: document.getElementById('address').value || null,
        profile_image: profileImagePath,
        join_date: document.getElementById('joinDate').value,
        membership_type: document.getElementById('membershipType').value,
        admission_fee: parseFloat(document.getElementById('admissionFee').value) || 0,
        monthly_fee: parseFloat(document.getElementById('monthlyFee').value) || 0,
        ptf_fee: parseFloat(document.getElementById('trainerFee').value) || 0,
        locker_fee: parseFloat(document.getElementById('lockerFee').value) || 0,
        next_fee_due_date: document.getElementById('nextFeeDueDate').value || null,
        status: document.getElementById('status').value,
        expected_updated_at: document.getElementById('memberUpdatedAt')?.value || null
    };

    const action = formData.id ? 'update' : 'create';
    const url = `api/members.php?action=${action}&gender=${currentGender}`;

    const submitMutation = window.MemberWriteOutbox && typeof window.MemberWriteOutbox.submitMemberMutation === 'function'
        ? window.MemberWriteOutbox.submitMemberMutation(action, { ...formData, profile_image: profileImagePath, gender: currentGender }, { gender: currentGender })
        : fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ...formData, profile_image: profileImagePath })
        }).then(res => res.json());

    submitMutation
        .then(data => {
            if (data.queued) {
                Utils.showNotification(data.message || 'Member saved offline. It will replay automatically when the connection returns.', 'warning');
                closeMemberModal();
                return;
            }

            if (data.success) {
                const resolutionItemId = document.getElementById('memberResolutionItemId')?.value || '';
                if (resolutionItemId && window.MemberWriteOutbox && typeof window.MemberWriteOutbox.removeQueuedItem === 'function') {
                    window.MemberWriteOutbox.removeQueuedItem(resolutionItemId);
                }
                Utils.showNotification(data.message || 'Member saved successfully', 'success');
                closeMemberModal();
                loadMembersTable();
            } else {
                Utils.showNotification(data.message || 'Failed to save member', 'error');
            }
        })
        .catch(err => {
            console.error('Save error:', err);
            Utils.showNotification('Error saving member', 'error');
        });
}

let isScanning = false;
let scanPollInterval = null;

function startRFIDScan() {
    if (isScanning) return;

    const btn = document.getElementById('scanRfidBtn');
    const statusFn = document.getElementById('scanStatus');
    const input = document.getElementById('rfidUid');

    isScanning = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Scanning...';
    btn.disabled = true;
    statusFn.innerHTML = '<span style="color: #2196F3;">Listening for admin scanner... Flash card now.</span>';

    // Poll for 30 seconds
    let attempts = 0;
    const maxAttempts = 30; // 30 seconds

    scanPollInterval = setInterval(() => {
        attempts++;

        // Stop after timeout
        if (attempts >= maxAttempts) {
            stopRFIDScan('Timeout: No card detected.', 'error');
            return;
        }

        fetch('api/rfid-assign.php?action=get_latest')
            .then(res => res.json())
            .then(data => {
                if (data.success && data.found && data.uid) {
                    // Check if timestamp is recent (within last 5 seconds)
                    const now = Math.floor(Date.now() / 1000);
                    if (now - data.timestamp < 10) {
                        input.value = data.uid;
                        stopRFIDScan('Card Scanned Successfully!', 'success');
                    }
                }
            })
            .catch(err => {
                console.error('Scan poll error:', err);
            });

    }, 1000);
}

function stopRFIDScan(message, type) {
    isScanning = false;
    clearInterval(scanPollInterval);

    const btn = document.getElementById('scanRfidBtn');
    const statusFn = document.getElementById('scanStatus');

    if (btn) {
        btn.innerHTML = '<i class="fas fa-wifi"></i> Scan';
        btn.disabled = false;
    }

    if (statusFn) {
        if (type === 'success') {
            statusFn.innerHTML = `<span style="color: #4CAF50;">${message}</span>`;
        } else {
            statusFn.innerHTML = `<span style="color: #f44336;">${message}</span>`;
        }
    }
}

function editMember(id) {
    if (!requireAdminAccess('edit members')) return;
    if (!Utils.isOnline()) {
        Utils.showNotification('Reconnect before editing member details. Existing-member edits need a live record.', 'warning');
        return;
    }

    fetch(`api/members.php?action=get&id=${id}&gender=${currentGender}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showAddMemberForm();
                const m = data.data;
                document.getElementById('memberId').value = m.id;
                document.getElementById('memberCode').value = m.member_code;
                document.getElementById('memberName').value = m.name;
                document.getElementById('phone').value = m.phone;
                document.getElementById('rfidUid').value = m.rfid_uid || '';
                document.getElementById('email').value = m.email || '';
                document.getElementById('address').value = m.address || '';
                document.getElementById('joinDate').value = m.join_date;
                populateMembershipTypeOptions(m.membership_type);
                document.getElementById('admissionFee').value = m.admission_fee;
                document.getElementById('monthlyFee').value = m.monthly_fee;
                document.getElementById('trainerFee').value = m.ptf_fee ?? 0;
                document.getElementById('lockerFee').value = m.locker_fee;
                document.getElementById('nextFeeDueDate').value = m.next_fee_due_date || '';
                document.getElementById('status').value = m.status;
                document.getElementById('memberUpdatedAt').value = m.updated_at || '';
                document.getElementById('existingProfileImage').value = m.profile_image || '';

                // Show existing profile image if available
                if (m.profile_image) {
                    const preview = document.getElementById('profileImagePreview');
                    const previewImg = document.getElementById('previewImg');
                    previewImg.src = m.profile_image;
                    preview.style.display = 'block';
                }

                // Update modal title
                document.querySelector('#memberModal .modal-header h2').textContent = 'Edit Member Details';
            } else {
                Utils.showNotification(data.message || 'Could not load member details for editing.', 'error');
            }
        })
        .catch(err => {
            console.error('Edit member error:', err);
            Utils.showNotification('Could not load member details for editing.', 'error');
        });
}

function deleteMember(id) {
    if (!requireAdminAccess('delete members')) return;
    if (!Utils.isOnline()) {
        Utils.showNotification('Reconnect before deleting members. Offline deletions are not queued.', 'warning');
        return;
    }
    if (!confirm('Are you sure you want to delete this member?')) return;

    fetch(`api/members.php?action=delete&id=${id}&gender=${currentGender}`, {
        method: 'DELETE'
    })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                Utils.showNotification('Member deleted successfully', 'success');
                loadMembersTable();
            } else {
                Utils.showNotification(data.message || 'Failed to delete member', 'error');
            }
        });
}

function openMemberProfile(memberCode, gender) {
    if (!memberCode) return;

    // Choose correct profile page based on gender
    const profilePage = gender === 'women' ? 'member-profile-women.html' : 'member-profile-men.html';
    const url = `${profilePage}?code=${encodeURIComponent(memberCode)}`;

    // Open in new tab so admin can keep dashboard open
    window.open(url, '_blank');
}

function loadAttendance() {
    const offlineNotice = window.OfflineState && typeof window.OfflineState.renderCapabilityNotice === 'function'
        ? window.OfflineState.renderCapabilityNotice('attendance', {
            title: 'Front-desk offline readiness',
            body: 'Keyboard entry stays the primary flow. Attendance can queue locally, but the full CRM still needs an online renewal at least once every 7 days.'
        })
        : '';
    const html = `
        <div class="attendance-section">
            ${renderSectionGuideCard({
                chip: 'Attendance Help',
                title: 'Check members in or out',
                description: 'Keep the cursor in the code box, type the next member code, and press Enter or the check-in button. The system will find the member in either men or women automatically.',
                steps: [
                    'Type the next member code exactly as written on the card or account slip.',
                    'Press Enter to keep the desk flowing without leaving the keyboard.',
                    'Use the Check Out button in the list when the member leaves.'
                ]
            })}
            ${offlineNotice}
            <div class="section-header">
                <div class="gender-tabs">
                    <button class="gender-tab ${currentGender === 'men' ? 'active' : ''}" data-gender="men">Men</button>
                    <button class="gender-tab ${currentGender === 'women' ? 'active' : ''}" data-gender="women">Women</button>
                </div>
                <div class="section-actions">
                    <input type="text" id="attendanceMemberCode" placeholder="Type member code here" class="search-input">
                    <button class="btn btn-primary" id="checkInBtn">Check In Member</button>
                </div>
            </div>
            <div data-attendance-outbox-panel aria-live="polite"></div>
            <div id="attendanceAnalyticsContainer" style="margin-bottom:1.5rem;"></div>
            <div id="attendanceTableContainer"></div>
        </div>
    `;
    document.getElementById('contentBody').innerHTML = html;
    if (window.AttendanceOutbox && typeof window.AttendanceOutbox.refreshPanels === 'function') {
        window.AttendanceOutbox.refreshPanels();
    }

    document.querySelectorAll('.gender-tab').forEach(tab => {
        tab.addEventListener('click', function () {
            currentGender = this.dataset.gender;
            document.querySelectorAll('.gender-tab').forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            loadAttendanceTable();
        });
    });

    const checkInBtn = document.getElementById('checkInBtn');
    if (checkInBtn) {
        checkInBtn.addEventListener('click', handleCheckIn);
    }

    const memberCodeInput = document.getElementById('attendanceMemberCode');
    if (memberCodeInput) {
        memberCodeInput.addEventListener('keypress', function (e) {
            if (e.key === 'Enter') {
                handleCheckIn();
            }
        });
    }

    loadAttendanceAnalytics();
    loadAttendanceTable();
}

function loadAttendanceAnalytics() {
    const container = document.getElementById('attendanceAnalyticsContainer');
    if (!container) return;

    const range = window.analyticsRanges?.attendance || '30d';
    fetch(`api/reports.php?action=attendance&range=${encodeURIComponent(range)}`)
        .then(res => res.json())
        .then(result => {
            if (!result.success) throw new Error(result.message || 'Failed to load attendance analytics');
            const data = result.data || {};
            container.innerHTML = `
                ${renderRangeSelector('attendance', range)}
                <div class="activity-analytics-grid">
                    ${renderAnalyticsBlock('Daily Attendance', 'Last 30 days trend', 'attendancePageDailyChart', data.charts?.daily_attendance || [], 'line', '#166534')}
                    ${renderAnalyticsBlock('Gender Attendance', 'Men vs women visits', 'attendancePageGenderChart', data.charts?.gender_attendance || [], 'bar', '#0369a1')}
                </div>
            `;
            renderReportCharts([
                { id: 'attendancePageDailyChart', type: 'line', series: data.charts?.daily_attendance || [], color: '#166534' },
                { id: 'attendancePageGenderChart', type: 'bar', series: data.charts?.gender_attendance || [], color: '#0369a1' }
            ]);
        })
        .catch(err => {
            container.innerHTML = `<div class="error">${err.message}</div>`;
        });
}

async function handleCheckIn() {
    const memberCode = document.getElementById('attendanceMemberCode').value.trim();
    if (!memberCode) {
        Utils.showNotification('Please enter member code', 'error');
        return;
    }

    try {
        let memberId = null;
        let memberGender = null;
        let lookupMode = 'live';

        const lookupResult = await lookupMemberByCodeAcrossGenders(memberCode);
        if (lookupResult.success && lookupResult.data) {
            memberId = lookupResult.data.id;
            memberGender = lookupResult.gender;
            if (memberGender !== currentGender) {
                setCurrentGender(memberGender);
            }
        } else if (lookupResult.offline) {
            const cachedSnapshot = getCachedMemberProfileSnapshot(memberCode);
            const cachedProfile = cachedSnapshot?.profile || cachedSnapshot?.data || null;
            if (cachedProfile && cachedProfile.id) {
                memberId = cachedProfile.id;
                memberGender = cachedSnapshot.gender === 'women' ? 'women' : 'men';
                lookupMode = 'cached';
                if (memberGender !== currentGender) {
                    setCurrentGender(memberGender);
                }
            } else {
                Utils.showNotification('Offline check-in only works for recently cached members that were already opened on this device.', 'warning');
                return;
            }
        } else {
            Utils.showNotification(lookupResult.message || 'Member not found. Please check the member code.', 'error');
            return;
        }

        if (!memberId || !memberGender) {
            Utils.showNotification('Member not found. Please check the member code.', 'error');
            return;
        }

        const attendancePayload = {
            member_id: memberId,
            gender: memberGender
        };

        if (lookupMode === 'cached' && !Utils.isOnline() && !(window.AttendanceOutbox && typeof window.AttendanceOutbox.submitCheckIn === 'function')) {
            Utils.showNotification('Offline check-in needs the attendance outbox module to be available on this device.', 'warning');
            return;
        }

        const submitCheckIn = window.AttendanceOutbox && typeof window.AttendanceOutbox.submitCheckIn === 'function'
            ? window.AttendanceOutbox.submitCheckIn(attendancePayload)
            : fetch('api/attendance-checkin.php?action=checkin', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
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

        if (result.queued) {
            const queuedMessage = lookupMode === 'cached'
                ? 'Check-in saved offline from a cached profile. It will replay automatically when the connection returns.'
                : 'Check-in saved offline. It will replay automatically when the connection returns.';
            Utils.showNotification(queuedMessage, 'warning');
            document.getElementById('attendanceMemberCode').value = '';
            if (window.AttendanceOutbox) {
                window.AttendanceOutbox.refreshPanels();
            }
            return;
        }

        if (result.success) {
            Utils.showNotification('Member checked in successfully.', 'success');
            document.getElementById('attendanceMemberCode').value = '';
            loadAttendanceTable();
        } else {
            Utils.showNotification(result.message || 'Failed to record check-in', 'error');
        }
    } catch (error) {
        console.error('Check-in error:', error);
        Utils.showNotification('Failed to record check-in: ' + error.message, 'error');
    }
}

function loadAttendanceTable(page = 1) {
    // Cancel previous in-flight request for attendance
    if (activeRequests['attendance']) {
        activeRequests['attendance'].abort();
    }
    const abortController = new AbortController();
    activeRequests['attendance'] = abortController;

    fetch(`api/attendance.php?action=list&gender=${currentGender}&page=${page}`, { signal: abortController.signal })
        .then(async res => {
            if (abortController.signal.aborted) return null;
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
        })
        .then(data => {
            if (abortController.signal.aborted) return;
            if (!data) return;
            if (data.success) {
                if (window.OfflineState && typeof window.OfflineState.recordOnlineSuccess === 'function') {
                    window.OfflineState.recordOnlineSuccess('attendance', { source: 'loadAttendanceTable' });
                }
                const pagination = data.pagination || { page: 1, limit: 20 };
                const currentPage = parseInt(pagination.page) || 1;
                const limit = parseInt(pagination.limit) || 20;
                const startIndex = (currentPage - 1) * limit;

                const html = `
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Member Code</th>
                                <th>Name</th>
                                <th>Check In</th>
                                <th>Check Out</th>
                                <th>Duration</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${data.data.map((a, idx) => `
                                <tr>
                                    <td data-label="#">${startIndex + idx + 1}</td>
                                    <td data-label="Member Code">${a.member_code}</td>
                                    <td data-label="Name">${a.name}</td>
                                    <td data-label="Check In">${new Date(a.check_in).toLocaleString()}</td>
                                    <td data-label="Check Out">${a.check_out ? new Date(a.check_out).toLocaleString() : '<span style="color: orange;">In Progress</span>'}</td>
                                    <td data-label="Duration">${a.duration_minutes ? a.duration_minutes + ' min' : 'N/A'}</td>
                                    <td data-label="Actions">
                                        ${!a.check_out ? `<button class="btn btn-sm btn-primary" onclick="checkOut(${a.id})">Check Out</button>` : ''}
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                `;
                document.getElementById('attendanceTableContainer').innerHTML = html;
            } else {
                document.getElementById('attendanceTableContainer').innerHTML = `<div class="error">${data.message || 'Failed to load attendance'}</div>`;
            }
        })
        .catch(error => {
            if (abortController.signal.aborted) return;
            console.error('Attendance table error:', error);
            document.getElementById('attendanceTableContainer').innerHTML = `<div class="error">Error loading attendance: ${error.message}</div>`;
        });
}

function checkOut(attendanceId) {
    const attendancePayload = {
        attendance_id: attendanceId,
        gender: currentGender
    };

    const submitCheckOut = window.AttendanceOutbox && typeof window.AttendanceOutbox.submitCheckOut === 'function'
        ? window.AttendanceOutbox.submitCheckOut(attendancePayload)
        : fetch('api/attendance-checkin.php?action=checkout', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
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

    submitCheckOut
        .then(data => {
            if (data.queued) {
                Utils.showNotification('Check-out saved offline. It will replay automatically when the connection returns.', 'warning');
                if (window.AttendanceOutbox) {
                    window.AttendanceOutbox.refreshPanels();
                }
                return;
            }

            if (data.success) {
                Utils.showNotification('Member checked out successfully.', 'success');
                loadAttendanceTable();
            } else {
                Utils.showNotification(data.message || 'Failed to record check-out', 'error');
            }
        })
        .catch(error => {
            console.error('Check-out error:', error);
            Utils.showNotification('Failed to record check-out: ' + error.message, 'error');
        });
}

let paymentsViewMode = 'current'; // 'current' or 'history'
let paymentsSelectedMonth = new Date().getMonth() + 1;
let paymentsSelectedYear = new Date().getFullYear();

let expensesViewMode = 'current'; // 'current' or 'history'
let expensesSelectedMonth = new Date().getMonth() + 1;
let expensesSelectedYear = new Date().getFullYear();

function loadPayments() {
    const html = `
        <div class="payments-section">
            ${renderSectionGuideCard({
                chip: 'Payments Help',
                title: 'Record money received or review late payers',
                description: 'Use Take Payment for someone paying now. Use Show Late Payers only when you want to see members with unpaid dues.',
                steps: [
                    'Search by member code or name if the list is long.',
                    'Keep This Month selected for daily front-desk work.',
                    'Switch to Older Payments only when you need past records.'
                ]
            })}
            <div class="section-header">
                <div class="gender-tabs">
                    <button class="gender-tab ${currentGender === 'men' ? 'active' : ''}" data-gender="men">Men</button>
                    <button class="gender-tab ${currentGender === 'women' ? 'active' : ''}" data-gender="women">Women</button>
                </div>
                <div class="section-actions">
                    <input type="text" id="paymentSearch" placeholder="Search by member code, name, or invoice" class="search-input">
                    <div style="display: flex; gap: 0.5rem; align-items: center;">
                        <button class="btn ${paymentsViewMode === 'current' ? 'btn-primary' : 'btn-secondary'}" id="viewCurrentBtn">This Month</button>
                        <button class="btn ${paymentsViewMode === 'history' ? 'btn-primary' : 'btn-secondary'}" id="viewHistoryBtn">Older Payments</button>
                    </div>
                    <div id="historySelector" style="display: ${paymentsViewMode === 'history' ? 'flex' : 'none'}; gap: 0.5rem; align-items: center; margin-left: 0.5rem;">
                        <select id="paymentMonth" class="search-input" style="width: auto;">
                            ${Array.from({ length: 12 }, (_, i) => {
        const month = i + 1;
        const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        return `<option value="${month}" ${month === paymentsSelectedMonth ? 'selected' : ''}>${monthNames[i]}</option>`;
    }).join('')}
                        </select>
                        <select id="paymentYear" class="search-input" style="width: auto;">
                            ${Array.from({ length: 5 }, (_, i) => {
        const year = new Date().getFullYear() - i;
        return `<option value="${year}" ${year === paymentsSelectedYear ? 'selected' : ''}>${year}</option>`;
    }).join('')}
                        </select>
                        <button class="btn btn-primary" id="loadHistoryBtn">Load</button>
                    </div>
                    <button class="btn ${paymentsDefaultersFilter ? 'btn-warning' : 'btn-secondary'}" id="showDefaultersBtn">Show Late Payers</button>
                    <button class="btn ${memberStatusFilter === 'inactive' ? 'btn-primary' : 'btn-secondary'}" id="showInactivePaymentsBtn">Inactive Members</button>
                    <button class="btn ${memberStatusFilter === 'active' ? 'btn-primary' : 'btn-secondary'}" id="showActivePaymentsBtn">Active Members</button>
                    ${isAdminUser() ? '<button class="btn btn-primary" id="addPaymentBtn">Take Payment</button>' : ''}
                </div>
            </div>
            <div id="paymentsAnalyticsContainer" style="margin-bottom:1.5rem;"></div>
            <div id="paymentsTableContainer"></div>
        </div>
    `;
    document.getElementById('contentBody').innerHTML = html;

    document.querySelectorAll('.gender-tab').forEach(tab => {
        tab.addEventListener('click', function () {
            currentGender = this.dataset.gender;
            document.querySelectorAll('.gender-tab').forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            loadPaymentsTable();
        });
    });

    const addPaymentBtn = document.getElementById('addPaymentBtn');
    if (addPaymentBtn) {
        addPaymentBtn.addEventListener('click', showAddPaymentForm);
    }

    const viewCurrentBtn = document.getElementById('viewCurrentBtn');
    const viewHistoryBtn = document.getElementById('viewHistoryBtn');
    const historySelector = document.getElementById('historySelector');
    const loadHistoryBtn = document.getElementById('loadHistoryBtn');
    const paymentMonth = document.getElementById('paymentMonth');
    const paymentYear = document.getElementById('paymentYear');

    if (viewCurrentBtn) {
        viewCurrentBtn.addEventListener('click', function () {
            paymentsViewMode = 'current';
            viewCurrentBtn.classList.remove('btn-secondary');
            viewCurrentBtn.classList.add('btn-primary');
            viewHistoryBtn.classList.remove('btn-primary');
            viewHistoryBtn.classList.add('btn-secondary');
            historySelector.style.display = 'none';
            loadPaymentsTable();
        });
    }

    if (viewHistoryBtn) {
        viewHistoryBtn.addEventListener('click', function () {
            paymentsViewMode = 'history';
            viewHistoryBtn.classList.remove('btn-secondary');
            viewHistoryBtn.classList.add('btn-primary');
            viewCurrentBtn.classList.remove('btn-primary');
            viewCurrentBtn.classList.add('btn-secondary');
            historySelector.style.display = 'flex';
        });
    }

    if (loadHistoryBtn) {
        loadHistoryBtn.addEventListener('click', function () {
            paymentsSelectedMonth = parseInt(paymentMonth.value);
            paymentsSelectedYear = parseInt(paymentYear.value);
            loadPaymentsTable();
        });
    }

    // Setup search
    const searchInput = document.getElementById('paymentSearch');
    if (searchInput) {
        searchInput.addEventListener('input', Utils.debounce(function () {
            loadPaymentsTable();
        }, 300));
    }

    // Setup defaulters button
    const showDefaultersBtn = document.getElementById('showDefaultersBtn');
    if (showDefaultersBtn) {
        showDefaultersBtn.addEventListener('click', function () {
            paymentsDefaultersFilter = !paymentsDefaultersFilter;
            if (paymentsDefaultersFilter) {
                showDefaultersBtn.classList.remove('btn-secondary');
                showDefaultersBtn.classList.add('btn-warning');
                showDefaultersBtn.textContent = 'Back to Payment List';
            } else {
                showDefaultersBtn.classList.remove('btn-warning');
                showDefaultersBtn.classList.add('btn-secondary');
                showDefaultersBtn.textContent = 'Show Late Payers';
            }
            loadPaymentsTable(1);
        });
    }

    // Setup Inactive Payments Button
    const showInactivePaymentsBtn = document.getElementById('showInactivePaymentsBtn');
    const showActivePaymentsBtn = document.getElementById('showActivePaymentsBtn');
    if (showInactivePaymentsBtn) {
        showInactivePaymentsBtn.addEventListener('click', function () {
            memberStatusFilter = memberStatusFilter === 'inactive' ? null : 'inactive';
            if (showActivePaymentsBtn) {
                showActivePaymentsBtn.classList.remove('btn-primary');
                showActivePaymentsBtn.classList.add('btn-secondary');
            }
            loadPayments();
        });
    }

    if (showActivePaymentsBtn) {
        showActivePaymentsBtn.addEventListener('click', function () {
            memberStatusFilter = memberStatusFilter === 'active' ? null : 'active';
            if (showInactivePaymentsBtn) {
                showInactivePaymentsBtn.classList.remove('btn-primary');
                showInactivePaymentsBtn.classList.add('btn-secondary');
            }
            loadPayments();
        });
    }

    loadPaymentsAnalytics();
    loadPaymentsTable();
}

function loadPaymentsAnalytics() {
    const container = document.getElementById('paymentsAnalyticsContainer');
    if (!container) return;

    const range = window.analyticsRanges?.payments || '30d';
    fetch(`api/reports.php?action=payments&range=${encodeURIComponent(range)}`)
        .then(res => res.json())
        .then(result => {
            if (!result.success) throw new Error(result.message || 'Failed to load payments analytics');
            const data = result.data || {};
            container.innerHTML = `
                ${renderRangeSelector('payments', range)}
                <div class="activity-analytics-grid">
                    ${renderAnalyticsBlock('Daily Revenue', 'Last 30 days', 'paymentsPageDailyChart', data.charts?.daily_revenue || [], 'line', '#166534')}
                    ${renderAnalyticsBlock('Monthly Revenue', 'Month-by-month', 'paymentsPageMonthlyChart', data.charts?.monthly_revenue || [], 'line', '#0369a1')}
                    ${renderAnalyticsBlock('Payment Methods', 'Most used methods', 'paymentsPageMethodChart', data.charts?.payment_methods || [], 'bar', '#7c3aed')}
                </div>
            `;
            renderReportCharts([
                { id: 'paymentsPageDailyChart', type: 'line', series: data.charts?.daily_revenue || [], color: '#166534' },
                { id: 'paymentsPageMonthlyChart', type: 'line', series: data.charts?.monthly_revenue || [], color: '#0369a1' },
                { id: 'paymentsPageMethodChart', type: 'bar', series: data.charts?.payment_methods || [], color: '#7c3aed' }
            ]);
        })
        .catch(err => {
            container.innerHTML = `<div class="error">${err.message}</div>`;
        });
}

function showAddPaymentForm() {
    if (!requireAdminAccess('record payments')) return;

    const html = `
        <div class="modal" id="paymentModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>Take Payment</h2>
                    <button class="modal-close" onclick="closePaymentModal()">&times;</button>
                </div>
                <form id="paymentForm" class="modal-body">
                    <input type="hidden" id="paymentResolutionItemId" name="resolution_outbox_item_id">
                    <div class="simple-note"><strong>Tip:</strong> Type member code first, then enter how much money you received.</div>
                    <div id="paymentConflictResolutionNote" style="display:none;margin:0.75rem 0;padding:0.75rem 0.9rem;border-left:4px solid #f59e0b;background:#fffbeb;color:#7c2d12;border-radius:6px;"></div>
                    <div class="form-group">
                        <label>Member Code / Account No. *</label>
                        <input type="text" id="paymentMemberCode" name="member_code" placeholder="Example: M001" required>
                    </div>
                    <div class="form-group">
                        <label>Amount Received *</label>
                        <input type="number" step="0.01" id="paymentAmount" name="amount" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Payment Date *</label>
                            <input type="date" id="paymentDate" name="payment_date" required>
                        </div>
                        <div class="form-group">
                            <label>Due Date</label>
                            <input type="date" id="dueDate" name="due_date">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Invoice Number</label>
                        <input type="text" id="invoiceNumber" name="invoice_number">
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select id="paymentStatus" name="status">
                            <option value="completed">Completed</option>
                            <option value="pending">Pending</option>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Staff Name</label>
                            <select id="paymentReceivedBy" name="received_by">
                                <option value="Admin One">Admin One</option>
                                <option value="Admin Two">Admin Two</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Payment Method</label>
                            <select id="paymentMethod" name="payment_method">
                                <option value="Cash">Cash</option>
                                <option value="Online">Online</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closePaymentModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Payment</button>
                    </div>
                </form>
            </div>
        </div>
    `;
    document.body.insertAdjacentHTML('beforeend', html);

    // Set today's date as default
    document.getElementById('paymentDate').valueAsDate = new Date();

    const form = document.getElementById('paymentForm');
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        savePayment();
    });
}

function closePaymentModal() {
    const modal = document.getElementById('paymentModal');
    if (modal) modal.remove();
}

async function savePayment() {
    const memberCode = document.getElementById('paymentMemberCode').value.trim();
    const paymentAmount = parseFloat(document.getElementById('paymentAmount').value);
    const paymentDate = document.getElementById('paymentDate').value;
    const dueDate = document.getElementById('dueDate').value || null;
    const invoiceNumber = document.getElementById('invoiceNumber').value || null;
    const paymentStatus = document.getElementById('paymentStatus').value;
    const receivedBy = document.getElementById('paymentReceivedBy').value;
    const paymentMethod = document.getElementById('paymentMethod').value;

    if (!memberCode) {
        Utils.showNotification('Please enter member code', 'error');
        return;
    }

    if (!Number.isFinite(paymentAmount) || paymentAmount <= 0) {
        Utils.showNotification('Please enter a valid payment amount', 'error');
        return;
    }

    if (!paymentDate) {
        Utils.showNotification('Please select a payment date', 'error');
        return;
    }

    const offlineState = window.OfflineState;
    const offlineCapability = offlineState && typeof offlineState.getCapabilityStatus === 'function'
        ? offlineState.getCapabilityStatus('member-profile')
        : null;

    try {
        let memberContext = null;
        let targetGender = currentGender;

        if (Utils.isOnline()) {
            const memberData = await lookupMemberByCodeAcrossGenders(memberCode);
            if (!memberData.success || !memberData.data) {
                Utils.showNotification(memberData.message || 'Member not found', 'error');
                return;
            }

            if (memberData.gender && memberData.gender !== currentGender) {
                setCurrentGender(memberData.gender);
            }

            targetGender = memberData.gender || currentGender;
            memberContext = memberData.data;
        } else {
            if (offlineCapability && offlineCapability.canUseFullOffline === false) {
                Utils.showNotification(offlineCapability.message || 'Reconnect online to renew offline access before recording payments.', 'error');
                return;
            }

            const cachedSnapshot = getCachedMemberProfileSnapshot(memberCode);
            if (!cachedSnapshot || !cachedSnapshot.profile || !cachedSnapshot.profile.id) {
                Utils.showNotification('Reconnect once to cache this member before recording a payment offline.', 'error');
                return;
            }

            if (cachedSnapshot.gender && cachedSnapshot.gender !== currentGender) {
                setCurrentGender(cachedSnapshot.gender);
            }

            targetGender = cachedSnapshot.gender || currentGender;
            memberContext = cachedSnapshot.profile;
        }

        const expectedUpdatedAt = memberContext.updated_at || (() => {
            if (!memberContext.cached_at) return null;
            const stamp = new Date(memberContext.cached_at);
            return Number.isFinite(stamp.getTime()) ? stamp.toISOString().slice(0, 19).replace('T', ' ') : null;
        })();

        const paymentData = {
            member_id: memberContext.id,
            member_code: memberContext.member_code || memberCode,
            gender: targetGender,
            amount: paymentAmount,
            payment_date: paymentDate,
            due_date: dueDate,
            invoice_number: invoiceNumber,
            status: paymentStatus,
            received_by: receivedBy,
            payment_method: paymentMethod,
            expected_updated_at: expectedUpdatedAt,
            expected_total_due_amount: memberContext.total_due_amount ?? null
        };

        const paymentService = window.PaymentOutbox && typeof window.PaymentOutbox.submitPayment === 'function'
            ? window.PaymentOutbox.submitPayment
            : async function (payload) {
                const res = await fetch(`api/payments.php?action=create&gender=${encodeURIComponent(payload.gender || targetGender)}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                return res.ok ? data : { success: false, message: data.message || 'Failed to record payment' };
            };

        const result = await paymentService(paymentData, { source: 'admin-dashboard', gender: targetGender });

        if (result.queued) {
            Utils.showNotification(result.message || 'Payment saved offline. It will replay automatically when the connection returns.', 'warning');
            closePaymentModal();
            return;
        }

        if (result.success) {
            const resolutionItemId = document.getElementById('paymentResolutionItemId')?.value || '';
            if (resolutionItemId && window.PaymentOutbox && typeof window.PaymentOutbox.removeQueuedItem === 'function') {
                window.PaymentOutbox.removeQueuedItem(resolutionItemId);
            }
            Utils.showNotification(result.message || 'Payment recorded successfully', 'success');
            closePaymentModal();
            loadPaymentsTable();
            if (currentSection === 'members' && !document.querySelector('.modal')) {
                loadMembersTable();
            }
            if (currentSection === 'due-fees') {
                loadDueFeesTable();
            }
            if (currentSection === 'dashboard') {
                loadDashboard();
            }
        } else {
            Utils.showNotification(result.message || 'Failed to record payment', 'error');
        }
    } catch (err) {
        console.error('Payment error:', err);
        Utils.showNotification('Error recording payment', 'error');
    }
}

function loadPaymentsTable(page = 1) {
    // Cancel previous in-flight request for payments
    if (activeRequests['payments']) {
        activeRequests['payments'].abort();
    }
    const abortController = new AbortController();
    activeRequests['payments'] = abortController;

    // Ensure page is a number
    page = parseInt(page) || 1;

    const month = paymentsViewMode === 'current' ? new Date().getMonth() + 1 : paymentsSelectedMonth;
    const year = paymentsViewMode === 'current' ? new Date().getFullYear() : paymentsSelectedYear;
    const search = document.getElementById('paymentSearch')?.value || '';
    const defaultersParam = paymentsDefaultersFilter ? '&defaulters=1' : '';
    const effectivePaymentStatusFilter = memberStatusFilter;
    const statusParam = effectivePaymentStatusFilter ? `&status=${effectivePaymentStatusFilter}` : '';

    const container = document.getElementById('paymentsTableContainer');
    if (!container) return;

    container.innerHTML = '<div class="loading">Loading payments...</div>';

    fetch(`api/payments.php?action=list&gender=${currentGender}&page=${page}&month=${month}&year=${year}&search=${encodeURIComponent(search)}${defaultersParam}${statusParam}`, { signal: abortController.signal })
        .then(async res => {
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
        })
        .then(data => {
            if (abortController.signal.aborted) return;
            // Re-check container exists before setting innerHTML
            const container = document.getElementById('paymentsTableContainer');
            if (!container) {
                console.warn('Payments table container not found');
                return;
            }

            if (data && data.success) {
                let html = '';

                if (data.defaulters) {
                    const defaulters = (data.data || []).map(normalizeMemberStatus).filter(member => {
                        return effectivePaymentStatusFilter ? member.calculated_status === effectivePaymentStatusFilter : true;
                    });
                    const defaulterPagination = {
                        ...(data.pagination || {}),
                        total: defaulters.length,
                        pages: 1,
                        page: 1,
                        limit: defaulters.length || (data.pagination?.limit || 20)
                    };

                    // Defaulters view
                    html = `
                        <div style="margin-bottom: 1rem;">
                            <h3>Late Payers</h3>
                            <p>Members not paid for 1 month or more: ${defaulterPagination.total}</p>
                        </div>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Member Code</th>
                                    <th>Name</th>
                                    <th>Monthly Fee</th>
                                    <th>Total Due</th>
                                    <th>Last Payment Date</th>
                                    <th>Days Since Payment</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${defaulters.length > 0 ? defaulters.map((p, idx) => {
                        const daysSince = parseInt(p.days_since_payment) || 0;
                        return `
                                    <tr>
                                        <td data-label="#">${idx + 1}</td>
                                        <td data-label="Member Code">${p.member_code}</td>
                                        <td data-label="Name">${p.name}</td>
                                        <td data-label="Monthly Fee">${Utils.formatCurrency(p.monthly_fee || 0)}</td>
                                        <td data-label="Total Due"><span style="color: red; font-weight: bold;">${Utils.formatCurrency(p.total_due_amount || 0)}</span></td>
                                        <td data-label="Last Payment">${p.last_payment_date ? Utils.formatDate(p.last_payment_date) : 'Never'}</td>
                                        <td data-label="Days Since"><span style="color: ${daysSince > 60 ? 'red' : 'orange'}; font-weight: bold;">${daysSince} days</span></td>
                                        <td data-label="Status"><span class="status-badge status-${p.calculated_status || p.status}">${p.calculated_status || p.status}</span></td>
                                    </tr>
                                `;
                    }).join('') : '<tr><td colspan="8" style="text-align: center;"><div class="empty-state"><strong>No late payers found</strong>Everyone in this view is up to date right now.</div></td></tr>'}
                            </tbody>
                        </table>
                    `;
                } else {
                    // Regular payments view
                    const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
                        'July', 'August', 'September', 'October', 'November', 'December'];
                    html = `
                        <div style="margin-bottom: 1rem;">
                            <h3>Payments for ${monthNames[data.month - 1]} ${data.year}</h3>
                            <p>Total payment records: ${data.pagination.total}</p>
                        </div>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Member Code</th>
                                    <th>Name</th>
                                    <th>Amount Paid</th>
                                    <th>Remaining Due</th>
                                    <th>Payment Date</th>
                                    <th>Method</th>
                                    <th>Receiver</th>
                                    <th>Due Date</th>
                                    <th>Invoice #</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${data.data.length > 0 ? data.data.map((p, idx) => {
                        const remainingDue = parseFloat(p.remaining_amount) || 0;
                        return `
                                    <tr>
                                        <td data-label="#">${((parseInt(data.pagination.page) || 1) - 1) * (parseInt(data.pagination.limit) || 20) + idx + 1}</td>
                                        <td data-label="Member Code">${p.member_code}</td>
                                        <td data-label="Name">${p.name}</td>
                                        <td data-label="Amount Paid"><strong>${Utils.formatCurrency(p.amount)}</strong></td>
                                        <td data-label="Remaining Due">${remainingDue > 0 ? `<span style="color: red; font-weight: bold;">${Utils.formatCurrency(remainingDue)}</span>` : '<span style="color: green;">Paid</span>'}</td>
                                        <td data-label="Payment Date">${Utils.formatDate(p.payment_date)}</td>
                                        <td data-label="Method">${p.payment_method || 'Cash'}</td>
                                        <td data-label="Receiver">${p.received_by || '-'}</td>
                                        <td data-label="Due Date">${p.due_date ? Utils.formatDate(p.due_date) : 'N/A'}</td>
                                        <td data-label="Invoice #">${p.invoice_number || 'N/A'}</td>
                                        <td data-label="Status"><span class="status-badge status-${p.status}">${p.status}</span></td>
                                    </tr>
                                `;
                    }).join('') : '<tr><td colspan="11" style="text-align: center;"><div class="empty-state"><strong>No payments found</strong>No payment record matches this month or search.</div></td></tr>'}
                            </tbody>
                        </table>
                    `;
                }

                // Add pagination
                if (data.pagination.pages > 1) {
                    const currentPage = parseInt(data.pagination.page) || 1;
                    const totalPages = parseInt(data.pagination.pages) || 1;
                    html += `
                        <div class="pagination" style="margin-top: 1rem; display: flex; justify-content: center; align-items: center; gap: 1rem; flex-wrap: wrap;">
                            <button ${currentPage === 1 ? 'disabled' : ''} onclick="loadPaymentsTable(${currentPage - 1})">Previous</button>
                            <span>Page</span>
                            <input type="number" id="paymentsPageInput" min="1" max="${totalPages}" value="${currentPage}" style="width: 60px; padding: 0.25rem; text-align: center; border: 1px solid #ddd; border-radius: 4px;" onchange="const page = parseInt(this.value) || 1; if (page >= 1 && page <= ${totalPages}) loadPaymentsTable(page); else this.value = ${currentPage};" onkeypress="if(event.key === 'Enter') { const page = parseInt(this.value) || 1; if (page >= 1 && page <= ${totalPages}) loadPaymentsTable(page); else this.value = ${currentPage}; }">
                            <span>of ${totalPages}</span>
                            <button ${currentPage === totalPages ? 'disabled' : ''} onclick="loadPaymentsTable(${currentPage + 1})">Next</button>
                        </div>
                    `;
                }

                container.innerHTML = html;
            } else {
                container.innerHTML = '<div class="error">Could not load payments: ' + (data?.message || 'Unknown error') + '</div>';
            }
        })
        .catch(err => {
            console.error('Payments error:', err);
            const container = document.getElementById('paymentsTableContainer');
            if (container) {
                container.innerHTML = `<div class="error">Could not load payments: ${err.message}</div>`;
            }
        });
}

function updateFee(memberId, memberCode) {
    if (!requireAdminAccess('take fees')) return;

    // Get member details first
    fetch(`api/members.php?action=get&id=${memberId}&gender=${currentGender}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const member = data.data;
                showUpdateFeeForm(member);
            }
        });
}

function showUpdateFeeForm(member) {
    const html = `
        <div class="modal" id="updateFeeModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>Receive Fee / Update Dues - ${member.member_code}</h2>
                    <button class="modal-close" onclick="closeUpdateFeeModal()">&times;</button>
                </div>
                <form id="updateFeeForm" class="modal-body">
                    <input type="hidden" id="feeMemberId" value="${member.id}">
                    <div class="form-group">
                        <label>Member: <strong>${member.name}</strong></label>
                    </div>
                    <div class="form-group">
                        <label>Join Date: ${Utils.formatDate(member.join_date)}</label>
                    </div>
                    <div class="form-group">
                        <label>Monthly Fee: <strong>${Utils.formatCurrency(member.monthly_fee)}</strong></label>
                    </div>
                    ${member.total_due_amount > 0 ? `
                    <div class="form-group" style="background: rgba(255, 193, 7, 0.2); padding: 1rem; border-radius: 5px; border-left: 4px solid #ffc107;">
                        <label><strong style="color: #ffc107;">⚠️ Old unpaid amount: ${Utils.formatCurrency(member.total_due_amount)}</strong></label>
                        <p style="margin: 0.5rem 0 0 0; font-size: 0.9rem; color: #ffc107;">
                            This unpaid amount is included in the full payment total below.
                        </p>
                    </div>
                    ` : ''}
                    <div class="form-group" style="background: rgba(33, 150, 243, 0.2); padding: 1rem; border-radius: 5px; border-left: 4px solid #2196F3;">
                        <label><strong style="color: #2196F3;">Full amount to clear now:</strong></label>
                        <p style="margin: 0.5rem 0; font-size: 1.2rem; font-weight: bold; color: #64b5f6;">
                            ${Utils.formatCurrency((parseFloat(member.total_due_amount) || 0) + parseFloat(member.monthly_fee) || 0)}
                            <small style="font-size: 0.9rem; font-weight: normal;">
                                (Previous Due: ${Utils.formatCurrency(member.total_due_amount || 0)} + Monthly Fee: ${Utils.formatCurrency(member.monthly_fee || 0)})
                            </small>
                        </p>
                    </div>
                    <div class="form-group">
                        <label>Amount Received *</label>
                        <input type="number" step="0.01" id="feeAmount" name="amount" value="${(parseFloat(member.total_due_amount) || 0) + parseFloat(member.monthly_fee) || 0}" required>
                        <small style="color: #d1d5db;">
                            ${member.total_due_amount > 0 ?
            `To clear everything, enter ${Utils.formatCurrency((parseFloat(member.total_due_amount) || 0) + parseFloat(member.monthly_fee) || 0)}. This includes old unpaid amount plus this month's fee.` :
            'Enter how much money you received. The default value is the monthly fee.'}
                        </small>
                    </div>
                    <div id="paymentCalculation" style="background: var(--bg-secondary); color: var(--text-color); padding: 0.75rem; border-radius: 5px; margin-top: 0.5rem; font-size: 0.9rem; border: 1px solid var(--border-color);">
                        <strong>Payment Summary:</strong>
                        <div id="calcDetails" style="margin-top: 0.25rem; color: var(--text-secondary);">
                            ${member.total_due_amount > 0 ?
            `Previous Due: ${Utils.formatCurrency(member.total_due_amount)}<br>
                                 Monthly Fee: ${Utils.formatCurrency(member.monthly_fee)}<br>
                                 <strong>Total to Pay: ${Utils.formatCurrency((parseFloat(member.total_due_amount) || 0) + parseFloat(member.monthly_fee) || 0)}</strong>` :
            `Monthly Fee: ${Utils.formatCurrency(member.monthly_fee)}`}
                        </div>
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" id="isPartialPayment" name="is_partial_payment">
                            This is not full payment (some amount will stay unpaid)
                        </label>
                    </div>
                    <div class="form-group">
                        <label>Staff Receiving Payment *</label>
                        <div style="display: flex; gap: 1rem; margin-top: 0.5rem;">
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                <input type="radio" name="received_by" value="Admin 1" required> Admin 1
                            </label>
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                <input type="radio" name="received_by" value="Admin 2"> Admin 2
                            </label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Payment Method *</label>
                        <div style="display: flex; gap: 1rem; margin-top: 0.5rem;">
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                <input type="radio" name="payment_method" value="Cash" checked> Cash
                            </label>
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                <input type="radio" name="payment_method" value="Online"> Online
                            </label>
                        </div>
                    </div>
                    <div class="form-group" id="dueAmountGroup" style="display: none;">
                        <label>Amount Still Unpaid *</label>
                        <input type="number" step="0.01" id="dueAmount" name="due_amount" value="0" min="0">
                        <small>Enter the amount that will still remain unpaid after this payment.</small>
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" id="isDefaulterUpdate" name="is_defaulter_update">
                            Set a new due date for this unpaid member
                        </label>
                    </div>
                    <div class="form-group" id="defaulterDateGroup" style="display: none;">
                        <label>New Due Date *</label>
                        <input type="date" id="newDefaulterDate" name="new_defaulter_date">
                    </div>
                    <div class="simple-note"><strong>Note:</strong> Normal update moves the next fee date automatically. If you set a new due date manually, the date you choose will be used. Partial payment lets you keep some amount unpaid.</div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeUpdateFeeModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Fee Update</button>
                    </div>
                </form>
            </div>
        </div>
    `;
    document.body.insertAdjacentHTML('beforeend', html);

    const form = document.getElementById('updateFeeForm');
    const partialPaymentCheckbox = document.getElementById('isPartialPayment');
    const dueAmountGroup = document.getElementById('dueAmountGroup');
    const dueAmountInput = document.getElementById('dueAmount');
    const defaulterCheckbox = document.getElementById('isDefaulterUpdate');
    const defaulterDateGroup = document.getElementById('defaulterDateGroup');
    const defaulterDateInput = document.getElementById('newDefaulterDate');

    // Auto-calculate payment amount when partial payment checkbox changes
    const feeAmountInput = document.getElementById('feeAmount');
    const calcDetails = document.getElementById('calcDetails');
    const totalDue = (parseFloat(member.total_due_amount) || 0) + parseFloat(member.monthly_fee) || 0;

    // Function to update calculation display
    function updateCalculation() {
        const paymentAmount = parseFloat(feeAmountInput.value) || 0;
        const prevDue = parseFloat(member.total_due_amount) || 0;
        const monthlyFee = parseFloat(member.monthly_fee) || 0;

        if (prevDue > 0) {
            if (partialPaymentCheckbox.checked) {
                const remaining = parseFloat(dueAmountInput.value) || 0;
                calcDetails.innerHTML = `
                    Previous Due: ${Utils.formatCurrency(prevDue)}<br>
                    Monthly Fee: ${Utils.formatCurrency(monthlyFee)}<br>
                    Payment Made: ${Utils.formatCurrency(paymentAmount)}<br>
                    Remaining Due: <strong style="color: red;">${Utils.formatCurrency(remaining)}</strong>
                `;
            } else {
                const remaining = Math.max(0, totalDue - paymentAmount);
                calcDetails.innerHTML = `
                    Previous Due: ${Utils.formatCurrency(prevDue)}<br>
                    Monthly Fee: ${Utils.formatCurrency(monthlyFee)}<br>
                    Payment Made: ${Utils.formatCurrency(paymentAmount)}<br>
                    ${remaining > 0 ?
                        `<strong style="color: red;">Remaining Due: ${Utils.formatCurrency(remaining)}</strong>` :
                        '<strong style="color: green;">✅ Paid in Full</strong>'}
                `;
            }
        } else {
            calcDetails.innerHTML = `Monthly Fee: ${Utils.formatCurrency(monthlyFee)}`;
        }
    }

    partialPaymentCheckbox.addEventListener('change', function () {
        if (this.checked) {
            dueAmountGroup.style.display = 'block';
            dueAmountInput.required = true;
            // When partial payment, default to monthly fee only
            feeAmountInput.value = member.monthly_fee;
            updateCalculation();
        } else {
            dueAmountGroup.style.display = 'none';
            dueAmountInput.required = false;
            dueAmountInput.value = 0;
            // When full payment, default to total due (previous + monthly fee)
            feeAmountInput.value = totalDue;
            updateCalculation();
        }
    });

    // Update calculation when payment amount changes
    feeAmountInput.addEventListener('input', function () {
        if (partialPaymentCheckbox.checked) {
            const paymentAmount = parseFloat(this.value) || 0;
            const remaining = Math.max(0, totalDue - paymentAmount);
            dueAmountInput.value = remaining.toFixed(2);
        }
        updateCalculation();
    });

    // Update calculation when due amount changes (for partial payments)
    dueAmountInput.addEventListener('input', function () {
        updateCalculation();
    });

    // Initial calculation
    updateCalculation();

    defaulterCheckbox.addEventListener('change', function () {
        if (this.checked) {
            defaulterDateGroup.style.display = 'block';
            defaulterDateInput.required = true;
        } else {
            defaulterDateGroup.style.display = 'none';
            defaulterDateInput.required = false;
        }
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        saveFeeUpdate();
    });
}

function closeUpdateFeeModal() {
    const modal = document.getElementById('updateFeeModal');
    if (modal) modal.remove();
}

function saveFeeUpdate() {
    const memberId = document.getElementById('feeMemberId').value;
    const amount = parseFloat(document.getElementById('feeAmount').value);
    const isPartialPayment = document.getElementById('isPartialPayment').checked;
    const dueAmount = parseFloat(document.getElementById('dueAmount').value) || 0;
    const isDefaulterUpdate = document.getElementById('isDefaulterUpdate').checked;
    const newDefaulterDate = document.getElementById('newDefaulterDate').value;

    // Get radio values
    const receivedBy = document.querySelector('input[name="received_by"]:checked')?.value;
    const paymentMethod = document.querySelector('input[name="payment_method"]:checked')?.value;

    if (!receivedBy) {
        Utils.showNotification('Please select who received the payment (Admin 1 or Admin 2)', 'error');
        return;
    }

    if (isPartialPayment && dueAmount <= 0) {
        Utils.showNotification('Please enter due amount for partial payment', 'error');
        return;
    }

    if (isDefaulterUpdate && !newDefaulterDate) {
        Utils.showNotification('Please select a new defaulter date', 'error');
        return;
    }

    const feeData = {
        member_id: memberId,
        gender: currentGender,
        amount: amount,
        is_partial_payment: isPartialPayment,

        due_amount: isPartialPayment ? dueAmount : 0,
        is_defaulter_update: isDefaulterUpdate,
        new_defaulter_date: isDefaulterUpdate ? newDefaulterDate : null,
        received_by: receivedBy,
        payment_method: paymentMethod
    };

    fetch('api/update-fee.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(feeData)
    })
        // Log The JSON Data
        .then(async res => {
            // Response received
            // Check if response is OK
            if (!res.ok) {
                // Try to get error message from response
                let errorMessage = 'Failed to update fee';
                try {
                    const errorData = await res.json();
                    errorMessage = errorData.message || errorMessage;
                } catch (e) {
                    // If response is not JSON, use status text
                    errorMessage = `Error ${res.status}: ${res.statusText || 'Server error'}`;
                }
                throw new Error(errorMessage);
            }

            // Check if response has content
            const contentType = res.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                throw new Error('Invalid response format from server');
            }

            // Get response text first to check if it's empty
            const text = await res.text();
            if (!text || text.trim() === '') {
                throw new Error('Empty response from server');
            }

            // Parse JSON
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('Invalid JSON response:', text);
                throw new Error('Invalid JSON response from server');
            }
        })
        .then(data => {
            if (data.success) {
                const message = data.message || 'Fee updated successfully';
                Utils.showNotification(message, 'success');
                closeUpdateFeeModal();

                // Always refresh these tables after fee update
                loadMembersTable(); // Refresh member list to show updated due amounts

                // Refresh payments table - wait a moment to ensure payment is saved
                setTimeout(() => {
                    loadPaymentsTable();
                }, 500);

                // Refresh due fees table if it exists
                if (document.getElementById('dueFeesTableContainer')) {
                    setTimeout(() => {
                        loadDueFeesTable();
                    }, 500);
                }

                // If on dashboard, refresh it too to update revenue
                if (document.getElementById('dashboard-stats')) {
                    setTimeout(() => {
                        loadDashboard();
                    }, 500);
                }
            } else {
                Utils.showNotification(data.message || 'Failed to update fee', 'error');
            }
        })
        .catch(err => {
            console.error('Fee update error:', err);
            Utils.showNotification(err.message || 'Error updating fee', 'error');
        });
}

function loadStaff() {
    const html = `
        <div class="members-section">
            ${renderSectionGuideCard({
                chip: 'Staff Help',
                title: 'Manage staff accounts',
                description: 'Create front desk users and control who can log in to the dashboard.',
                steps: [
                    'Add a staff user with name, username, and password.',
                    'Use role Admin only for trusted full-access users.',
                    'Use role Staff for reception/front desk users.'
                ]
            })}
            <div class="section-header">
                <div class="section-actions">
                    <input type="text" id="staffSearch" placeholder="Search by name, username, or role" class="search-input">
                    <button class="btn btn-primary" id="addStaffBtn">Add Staff User</button>
                </div>
            </div>
            <div id="staffTableContainer"></div>
        </div>
    `;
    document.getElementById('contentBody').innerHTML = html;

    document.getElementById('staffSearch')?.addEventListener('input', Utils.debounce(() => loadStaffTable(1), 300));
    document.getElementById('addStaffBtn')?.addEventListener('click', showStaffForm);
    loadStaffTable(1);
}

function formatStaffAccess(row) {
    if (row.role === 'admin') return 'Full';
    if (!Number(row.access_enabled)) return '24/7';
    const names = { 1: 'Mon', 2: 'Tue', 3: 'Wed', 4: 'Thu', 5: 'Fri', 6: 'Sat', 7: 'Sun' };
    const days = String(row.access_days || '').split(',').map(Number).filter(n => n >= 1 && n <= 7).sort((a, b) => a - b);
    const dayStr = (!days.length || days.length === 7) ? 'Every day' : days.map(d => names[d]).join(' ');
    const hourStr = (row.access_start && row.access_end) ? `${row.access_start}–${row.access_end}` : 'all hours';
    return `${dayStr} · ${hourStr}`;
}

function loadStaffTable(page = 1) {
    const search = document.getElementById('staffSearch')?.value || '';
    fetch(`api/staff.php?action=list&page=${page}&search=${encodeURIComponent(search)}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) throw new Error(data.message || 'Failed to load staff');
            const rows = data.data || [];
            const pagination = data.pagination || { page: 1, pages: 1, limit: 20 };
            const startIndex = ((pagination.page || 1) - 1) * (pagination.limit || 20);
            document.getElementById('staffTableContainer').innerHTML = `
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th><th>Name</th><th>Username</th><th>Role</th><th>Section</th><th>Access</th><th>Created</th><th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rows.length ? rows.map((row, idx) => `
                            <tr>
                                <td data-label="#">${startIndex + idx + 1}</td>
                                <td data-label="Name">${row.name || '-'}</td>
                                <td data-label="Username">${row.username}</td>
                                <td data-label="Role"><span class="status-badge status-active">${row.role}</span></td>
                                <td data-label="Section">${row.role === 'admin' ? 'Both' : ({ men: 'Men', women: 'Women', both: 'Both' }[row.staff_section] || 'Both')}</td>
                                <td data-label="Access">${escapeHtml(formatStaffAccess(row))}</td>
                                <td data-label="Created">${Utils.formatDate(row.created_at)}</td>
                                <td data-label="Actions">
                                    <button class="btn btn-sm btn-primary" onclick="editStaff(${row.id})">Edit</button>
                                    <button class="btn btn-sm btn-danger" onclick="deleteStaff(${row.id})">Delete</button>
                                </td>
                            </tr>
                        `).join('') : '<tr><td colspan="8"><div class="empty-state"><strong>No staff found</strong>Add your first staff user here.</div></td></tr>'}
                    </tbody>
                </table>
                ${pagination.pages > 1 ? `
                    <div class="pagination" style="margin-top:1rem;display:flex;gap:1rem;justify-content:center;align-items:center;">
                        <button class="btn btn-secondary" ${pagination.page === 1 ? 'disabled' : ''} onclick="loadStaffTable(${pagination.page - 1})">Previous</button>
                        <span>Page ${pagination.page} of ${pagination.pages}</span>
                        <button class="btn btn-secondary" ${pagination.page === pagination.pages ? 'disabled' : ''} onclick="loadStaffTable(${pagination.page + 1})">Next</button>
                    </div>
                ` : ''}
            `;
        })
        .catch(err => {
            document.getElementById('staffTableContainer').innerHTML = `<div class="error">${err.message}</div>`;
        });
}

function showStaffForm(staff = null) {
    const isEdit = !!staff;
    const html = `
        <div class="modal" id="staffModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>${isEdit ? 'Edit Staff User' : 'Add Staff User'}</h2>
                    <button class="modal-close" onclick="closeStaffModal()">&times;</button>
                </div>
                <form id="staffForm" class="modal-body">
                    <input type="hidden" id="staffId" value="${staff?.id || ''}">
                    <div class="form-group"><label>Name *</label><input type="text" id="staffName" value="${staff?.name || ''}" required></div>
                    <div class="form-group"><label>Username *</label><input type="text" id="staffUsername" value="${staff?.username || ''}" required></div>
                    <div class="form-group"><label>Password ${isEdit ? '(leave empty to keep old password)' : '*'}</label>
                        <div class="pw-wrap" style="position:relative;"><input type="password" id="staffPassword" ${isEdit ? '' : 'required'} style="padding-right:2.75rem;"><button type="button" class="pw-toggle" aria-label="Show or hide password" onclick="var i=this.parentNode.querySelector('input');var s=i.type==='password';i.type=s?'text':'password';this.textContent=s?'🙈':'👁';" style="position:absolute;right:.5rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:1.15rem;line-height:1;padding:.15rem;color:inherit;">👁</button></div>
                    </div>
                    <div class="form-group"><label>Role</label><select id="staffRole"><option value="staff" ${staff?.role === 'staff' ? 'selected' : ''}>Staff</option><option value="admin" ${staff?.role === 'admin' ? 'selected' : ''}>Admin</option></select></div>
                    <div class="form-group"><label>Section (which side they manage)</label><select id="staffSection">
                        <option value="both" ${(!staff || staff?.staff_section === 'both') ? 'selected' : ''}>Both (combined)</option>
                        <option value="men" ${staff?.staff_section === 'men' ? 'selected' : ''}>Men only</option>
                        <option value="women" ${staff?.staff_section === 'women' ? 'selected' : ''}>Women only</option>
                    </select></div>
                    <div class="form-group" style="border-top:1px solid var(--border-color);padding-top:.85rem;">
                        <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;">
                            <input type="checkbox" id="staffAccessEnabled" ${staff?.access_enabled ? 'checked' : ''} style="width:auto;margin:0;">
                            Limit this staff's access to set days &amp; hours
                        </label>
                        <small class="form-hint" style="display:block;margin-top:.35rem;">Off = 24/7 access. Admins are never limited.</small>
                    </div>
                    <div class="form-group"><label>Allowed days <span style="color:var(--text-muted);font-weight:400;">(none ticked = every day)</span></label>
                        <div style="display:flex;flex-wrap:wrap;gap:.4rem;">
                            ${[['1', 'Mon'], ['2', 'Tue'], ['3', 'Wed'], ['4', 'Thu'], ['5', 'Fri'], ['6', 'Sat'], ['7', 'Sun']].map(([n, lbl]) => {
            const on = String(staff?.access_days || '').split(',').includes(n);
            return `<button type="button" class="staffDay" data-day="${n}" aria-pressed="${on ? 'true' : 'false'}" onclick="toggleStaffDay(this)" style="border:1px solid var(--border-color);border-radius:6px;padding:.45rem .75rem;cursor:pointer;font-weight:600;background:${on ? 'var(--brand-gold,#f5c518)' : 'transparent'};color:${on ? '#0d0d0d' : 'inherit'};">${lbl}</button>`;
        }).join('')}
                        </div>
                    </div>
                    <div style="display:flex;gap:.6rem;">
                        <div class="form-group" style="flex:1;"><label>Start time</label><input type="time" id="staffAccessStart" value="${staff?.access_start || ''}"></div>
                        <div class="form-group" style="flex:1;"><label>End time <span style="color:var(--text-muted);font-weight:400;">(blank = all hours)</span></label><input type="time" id="staffAccessEnd" value="${staff?.access_end || ''}"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeStaffModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Staff User</button>
                    </div>
                </form>
            </div>
        </div>
    `;
    document.body.insertAdjacentHTML('beforeend', html);
    document.getElementById('staffForm')?.addEventListener('submit', function (e) {
        e.preventDefault();
        saveStaff();
    });
}

function closeStaffModal() {
    document.getElementById('staffModal')?.remove();
}

// Day picker uses plain toggle buttons (not checkboxes) so a single tap always
// flips the state — nested checkbox+label was inconsistent on touch (some days
// needed a double tap).
function toggleStaffDay(btn) {
    const on = btn.getAttribute('aria-pressed') === 'true';
    btn.setAttribute('aria-pressed', on ? 'false' : 'true');
    btn.style.background = on ? 'transparent' : 'var(--brand-gold, #f5c518)';
    btn.style.color = on ? 'inherit' : '#0d0d0d';
}

function saveStaff() {
    const id = document.getElementById('staffId')?.value || null;
    const payload = {
        id,
        name: document.getElementById('staffName')?.value?.trim(),
        username: document.getElementById('staffUsername')?.value?.trim(),
        password: document.getElementById('staffPassword')?.value || '',
        role: document.getElementById('staffRole')?.value || 'staff',
        staff_section: document.getElementById('staffSection')?.value || 'both',
        access_enabled: document.getElementById('staffAccessEnabled')?.checked ? 1 : 0,
        access_days: Array.from(document.querySelectorAll('.staffDay[aria-pressed="true"]')).map(c => c.dataset.day).join(','),
        access_start: document.getElementById('staffAccessStart')?.value || '',
        access_end: document.getElementById('staffAccessEnd')?.value || ''
    };
    const action = id ? 'update' : 'create';
    fetch(`api/staff.php?action=${action}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
        .then(res => res.json())
        .then(data => {
            if (!data.success) throw new Error(data.message || 'Failed to save staff');
            Utils.showNotification(data.message || 'Saved successfully', 'success');
            closeStaffModal();
            loadStaffTable(1);
        })
        .catch(err => Utils.showNotification(err.message, 'error'));
}

function editStaff(id) {
    fetch(`api/staff.php?action=list&page=1&limit=100`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) throw new Error(data.message || 'Failed to load staff');
            const staff = (data.data || []).find(item => String(item.id) === String(id));
            if (!staff) throw new Error('Staff user not found');
            showStaffForm(staff);
        })
        .catch(err => Utils.showNotification(err.message, 'error'));
}

function deleteStaff(id) {
    if (!confirm('Are you sure you want to delete this staff user?')) return;
    fetch(`api/staff.php?action=delete&id=${id}`, { method: 'DELETE' })
        .then(res => res.json())
        .then(data => {
            if (!data.success) throw new Error(data.message || 'Failed to delete staff');
            Utils.showNotification(data.message || 'Deleted successfully', 'success');
            loadStaffTable(1);
        })
        .catch(err => Utils.showNotification(err.message, 'error'));
}

function getActivityActionLabel(action) {
    const labels = {
        member_created: 'Member Created',
        member_updated: 'Member Updated',
        member_deleted: 'Member Deleted',
        member_due_date_updated: 'Due Date Updated',
        payment_recorded: 'Payment Recorded',
        expense_created: 'Expense Added',
        expense_updated: 'Expense Updated',
        expense_deleted: 'Expense Deleted',
        staff_created: 'Staff Created',
        staff_updated: 'Staff Updated',
        staff_deleted: 'Staff Deleted'
    };
    return labels[action] || action || 'Unknown';
}

function getActivityActionClass(action) {
    if ((action || '').includes('deleted')) return 'danger';
    if ((action || '').includes('created') || (action || '').includes('recorded')) return 'success';
    if ((action || '').includes('updated')) return 'warning';
    return 'neutral';
}

function formatActivityDetails(details) {
    if (!details) return '<span class="activity-muted">No extra details</span>';
    const entries = Object.entries(details);
    if (!entries.length) return '<span class="activity-muted">No extra details</span>';
    return entries.map(([key, value]) => `
        <span class="activity-detail-pill">
            <strong>${String(key).replace(/_/g, ' ')}:</strong> ${value === null || value === '' ? '-' : value}
        </span>
    `).join('');
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function openActivityModal(activity) {
    const detailsJson = activity?.details ? JSON.stringify(activity.details, null, 2) : 'No extra details';
    const modalHtml = `
        <div class="modal" id="activityDetailsModal">
            <div class="modal-content activity-modal-content">
                <div class="modal-header">
                    <h2>${escapeHtml(getActivityActionLabel(activity.action))}</h2>
                    <button class="modal-close" onclick="closeActivityModal()">&times;</button>
                </div>
                <div class="modal-body activity-modal-body">
                    <div class="activity-modal-grid">
                        <div class="activity-meta-item"><span class="activity-meta-label">Staff</span><strong>${escapeHtml(activity.admin_username || '-')}</strong></div>
                        <div class="activity-meta-item"><span class="activity-meta-label">Time</span><strong>${escapeHtml(activity.created_at || '-')}</strong></div>
                        <div class="activity-meta-item"><span class="activity-meta-label">Action</span><strong>${escapeHtml(activity.action || '-')}</strong></div>
                        <div class="activity-meta-item"><span class="activity-meta-label">Target Type</span><strong>${escapeHtml(activity.target_type || '-')}</strong></div>
                        <div class="activity-meta-item"><span class="activity-meta-label">Target ID</span><strong>${escapeHtml(activity.target_id || '-')}</strong></div>
                        <div class="activity-meta-item"><span class="activity-meta-label">IP Address</span><strong>${escapeHtml(activity.ip_address || '-')}</strong></div>
                    </div>
                    <div class="activity-details-wrap" style="margin-top:1rem;">
                        <div class="activity-details-title">Quick Details</div>
                        <div class="activity-details-pills">${formatActivityDetails(activity.details)}</div>
                    </div>
                    <div class="activity-details-wrap" style="margin-top:1rem;">
                        <div class="activity-details-title">Full JSON Details</div>
                        <pre class="activity-json-view">${escapeHtml(detailsJson)}</pre>
                    </div>
                </div>
            </div>
        </div>
    `;
    document.body.insertAdjacentHTML('beforeend', modalHtml);
}

function closeActivityModal() {
    document.getElementById('activityDetailsModal')?.remove();
}

window.chartMeta = window.chartMeta || {};

function attachChartTooltip(canvas, points, formatter) {
    if (!canvas) return;
    canvas.onmousemove = function (event) {
        const rect = canvas.getBoundingClientRect();
        const x = ((event.clientX - rect.left) / rect.width) * canvas.width;
        const y = ((event.clientY - rect.top) / rect.height) * canvas.height;
        const hit = points.find(point => Math.hypot(point.x - x, point.y - y) < 10);
        let tooltip = canvas.parentElement.querySelector('.chart-tooltip');
        if (!tooltip) {
            tooltip = document.createElement('div');
            tooltip.className = 'chart-tooltip';
            canvas.parentElement.style.position = 'relative';
            canvas.parentElement.appendChild(tooltip);
        }
        if (hit) {
            tooltip.style.display = 'block';
            tooltip.style.left = `${event.offsetX + 12}px`;
            tooltip.style.top = `${event.offsetY + 12}px`;
            tooltip.innerHTML = formatter(hit.data);
        } else {
            tooltip.style.display = 'none';
        }
    };
    canvas.onmouseleave = function () {
        const tooltip = canvas.parentElement.querySelector('.chart-tooltip');
        if (tooltip) tooltip.style.display = 'none';
    };
}

function drawChartAxes(ctx, width, height, padding, maxValue, tickCount = 4) {
    ctx.strokeStyle = '#d1d5db';
    ctx.lineWidth = 1;

    ctx.beginPath();
    ctx.moveTo(padding, padding / 2);
    ctx.lineTo(padding, height - padding);
    ctx.lineTo(width - padding, height - padding);
    ctx.stroke();

    ctx.fillStyle = '#6b7280';
    ctx.font = '11px Arial';

    for (let i = 0; i <= tickCount; i++) {
        const value = Math.round((maxValue / tickCount) * i);
        const y = height - padding - ((value / maxValue) * (height - padding * 2));
        ctx.beginPath();
        ctx.moveTo(padding - 5, y);
        ctx.lineTo(padding, y);
        ctx.stroke();
        ctx.fillText(String(value), 6, y + 4);
    }
}

function renderSimpleLineChart(canvasId, series = [], color = '#166534') {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const width = canvas.width;
    const height = canvas.height;
    ctx.clearRect(0, 0, width, height);

    if (!series.length) {
        ctx.fillStyle = '#6b7280';
        ctx.font = '14px Arial';
        ctx.fillText('No data available', 20, 30);
        return;
    }

    const padding = 30;
    const maxValue = Math.max(1, ...series.map(item => Number(item.total) || 0));
    const stepX = series.length > 1 ? (width - padding * 2) / (series.length - 1) : 0;

    drawChartAxes(ctx, width, height, padding, maxValue);

    const points = [];
    ctx.strokeStyle = color;
    ctx.lineWidth = 3;
    ctx.beginPath();
    series.forEach((item, index) => {
        const x = padding + index * stepX;
        const y = height - padding - ((Number(item.total) || 0) / maxValue) * (height - padding * 2);
        points.push({ x, y, data: item });
        if (index === 0) ctx.moveTo(x, y);
        else ctx.lineTo(x, y);

        if (index === 0 || index === series.length - 1 || index % Math.ceil(series.length / 4) === 0) {
            ctx.fillStyle = '#6b7280';
            ctx.font = '10px Arial';
            ctx.fillText(String(item.label).slice(0, 8), x - 12, height - 10);
        }
    });
    ctx.stroke();

    ctx.fillStyle = color;
    points.forEach(point => {
        ctx.beginPath();
        ctx.arc(point.x, point.y, 4, 0, Math.PI * 2);
        ctx.fill();
    });

    attachChartTooltip(canvas, points, item => `${item.label}: ${item.total}`);
}

function renderSimpleBarChart(canvasId, series = [], color = '#0369a1') {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const width = canvas.width;
    const height = canvas.height;
    ctx.clearRect(0, 0, width, height);

    if (!series.length) {
        ctx.fillStyle = '#6b7280';
        ctx.font = '14px Arial';
        ctx.fillText('No data available', 20, 30);
        return;
    }

    const padding = 30;
    const maxValue = Math.max(1, ...series.map(item => Number(item.total) || 0));
    const barArea = width - padding * 2;
    const barWidth = Math.max(18, (barArea / series.length) * 0.6);
    const gap = series.length > 0 ? barArea / series.length : 0;
    const points = [];

    drawChartAxes(ctx, width, height, padding, maxValue);

    series.forEach((item, index) => {
        const value = Number(item.total) || 0;
        const barHeight = (value / maxValue) * (height - padding * 2);
        const x = padding + index * gap + (gap - barWidth) / 2;
        const y = height - padding - barHeight;

        ctx.fillStyle = color;
        ctx.fillRect(x, y, barWidth, barHeight);
        points.push({ x: x + barWidth / 2, y, data: item });

        ctx.fillStyle = '#6b7280';
        ctx.font = '10px Arial';
        ctx.fillText(String(item.label).slice(0, 8), x, height - 10);
    });

    attachChartTooltip(canvas, points, item => `${item.label}: ${item.total}`);
}

function renderHorizontalBarChart(canvasId, series = [], color = '#dc2626') {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const W = canvas.width, H = canvas.height;
    ctx.clearRect(0, 0, W, H);
    if (!series.length) {
        ctx.fillStyle = '#9ca3af'; ctx.font = '13px Arial';
        ctx.fillText('No data available', 16, H / 2); return;
    }
    const labelW = 110, rightPad = 80, topPad = 10;
    const barAreaW = W - labelW - rightPad;
    const rowH = (H - topPad * 2) / series.length;
    const barH = Math.min(22, rowH * 0.55);
    const maxVal = Math.max(1, ...series.map(d => Number(d.total) || 0));
    const points = [];
    series.forEach((item, i) => {
        const val = Number(item.total) || 0;
        const bw = (val / maxVal) * barAreaW;
        const y = topPad + i * rowH + (rowH - barH) / 2;
        // Label
        ctx.fillStyle = '#374151'; ctx.font = '11px Arial'; ctx.textAlign = 'right';
        ctx.fillText(String(item.label).slice(0, 16), labelW - 6, y + barH / 2 + 4);
        // Bar with rounded right corners
        const radius = Math.min(5, barH / 2);
        ctx.fillStyle = color;
        ctx.beginPath();
        ctx.moveTo(labelW, y);
        ctx.lineTo(labelW + bw - radius, y);
        ctx.quadraticCurveTo(labelW + bw, y, labelW + bw, y + radius);
        ctx.lineTo(labelW + bw, y + barH - radius);
        ctx.quadraticCurveTo(labelW + bw, y + barH, labelW + bw - radius, y + barH);
        ctx.lineTo(labelW, y + barH);
        ctx.closePath();
        ctx.fill();
        // Value
        ctx.fillStyle = '#374151'; ctx.textAlign = 'left'; ctx.font = '10px Arial';
        const fmt = typeof Utils !== 'undefined' && Utils.formatCurrency ? Utils.formatCurrency(val) : val.toLocaleString();
        ctx.fillText(fmt, labelW + bw + 5, y + barH / 2 + 4);
        points.push({ x: labelW + bw / 2, y: y + barH / 2, data: item });
    });
    ctx.textAlign = 'left';
    attachChartTooltip(canvas, points, item => {
        const fmt = typeof Utils !== 'undefined' && Utils.formatCurrency ? Utils.formatCurrency(item.total) : item.total;
        return `${item.label}: ${fmt}`;
    });
}

function renderAttendanceHeatmap(canvasId, heatmapData) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const W = canvas.width, H = canvas.height;
    ctx.clearRect(0, 0, W, H);

    const now = new Date();
    const year = now.getFullYear();
    const month = now.getMonth(); // 0-based
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const firstDow = new Date(year, month, 1).getDay(); // 0=Sun

    // Build day-of-month → count map
    const dayMap = {};
    (heatmapData || []).forEach(d => {
        const n = parseInt(d.day.split('-')[2], 10);
        dayMap[n] = parseInt(d.total, 10) || 0;
    });
    const maxCount = Math.max(1, ...Object.values(dayMap));

    const cols = 7;
    const rows = Math.ceil((firstDow + daysInMonth) / 7);
    const PAD_L = 10, PAD_T = 34, PAD_R = 10, PAD_B = 8;
    const GAP = 3;
    const availW = W - PAD_L - PAD_R;
    const availH = H - PAD_T - PAD_B;
    const cellW = (availW - GAP * (cols - 1)) / cols;
    const cellH = (availH - GAP * (rows - 1)) / rows;
    const cell = Math.min(cellW, cellH);
    const gridW = cols * cell + GAP * (cols - 1);
    const offX = PAD_L + (availW - gridW) / 2;

    // Month/year label
    const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    ctx.font = 'bold 11px sans-serif';
    ctx.fillStyle = '#14532d';
    ctx.textAlign = 'left';
    ctx.fillText(monthNames[month] + ' ' + year, offX, 14);

    // Day-of-week column headers
    const DOW = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
    ctx.font = 'bold 9px sans-serif';
    ctx.fillStyle = '#6b7280';
    ctx.textAlign = 'center';
    DOW.forEach((d, i) => ctx.fillText(d, offX + i * (cell + GAP) + cell / 2, PAD_T - 8));

    // Draw cells and store for tooltip
    const cells = [];
    let day = 1;
    for (let row = 0; row < rows; row++) {
        for (let col = 0; col < cols; col++) {
            const slot = row * 7 + col;
            if (slot < firstDow || day > daysInMonth) continue;
            const cx = offX + col * (cell + GAP);
            const cy = PAD_T + row * (cell + GAP);
            const count = dayMap[day] || 0;
            const t = count === 0 ? 0 : Math.max(0.12, count / maxCount);
            // Interpolate #f0fdf4 (light green) → #166534 (dark green)
            const R = Math.round(240 - t * 218);
            const G = Math.round(253 - t * 152);
            const B = Math.round(244 - t * 192);
            const rad = Math.max(2, cell * 0.18);
            // Rounded rect
            ctx.beginPath();
            ctx.moveTo(cx + rad, cy);
            ctx.lineTo(cx + cell - rad, cy);
            ctx.arcTo(cx + cell, cy, cx + cell, cy + rad, rad);
            ctx.lineTo(cx + cell, cy + cell - rad);
            ctx.arcTo(cx + cell, cy + cell, cx + cell - rad, cy + cell, rad);
            ctx.lineTo(cx + rad, cy + cell);
            ctx.arcTo(cx, cy + cell, cx, cy + cell - rad, rad);
            ctx.lineTo(cx, cy + rad);
            ctx.arcTo(cx, cy, cx + rad, cy, rad);
            ctx.closePath();
            ctx.fillStyle = count === 0 ? '#f0fdf4' : `rgb(${R},${G},${B})`;
            ctx.fill();
            // Day number
            ctx.font = `${Math.max(7, Math.min(10, cell * 0.28))}px sans-serif`;
            ctx.fillStyle = t > 0.55 ? '#ffffff' : '#166534';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(String(day), cx + cell / 2, cy + cell / 2);
            cells.push({ cx, cy, cell, day, count,
                dateStr: `${year}-${String(month + 1).padStart(2,'0')}-${String(day).padStart(2,'0')}` });
            day++;
        }
    }
    ctx.textBaseline = 'alphabetic';

    // Legend: low → high
    const legendY = H - 4;
    ctx.font = '9px sans-serif'; ctx.fillStyle = '#6b7280'; ctx.textAlign = 'left';
    ctx.fillText('Less', offX, legendY);
    const swatchW = 10, swatchGap = 2;
    for (let i = 0; i <= 4; i++) {
        const t2 = i / 4;
        const R2 = Math.round(240 - t2 * 218), G2 = Math.round(253 - t2 * 152), B2 = Math.round(244 - t2 * 192);
        ctx.fillStyle = t2 === 0 ? '#f0fdf4' : `rgb(${R2},${G2},${B2})`;
        ctx.fillRect(offX + 28 + i * (swatchW + swatchGap), legendY - 9, swatchW, 9);
    }
    ctx.fillStyle = '#6b7280'; ctx.textAlign = 'left';
    ctx.fillText('More', offX + 28 + 5 * (swatchW + swatchGap) + 2, legendY);

    // Custom tooltip (rect-based, not point-based)
    canvas.onmousemove = function (e) {
        const rect = canvas.getBoundingClientRect();
        const mx = ((e.clientX - rect.left) / rect.width) * W;
        const my = ((e.clientY - rect.top) / rect.height) * H;
        const hit = cells.find(c => mx >= c.cx && mx <= c.cx + c.cell && my >= c.cy && my <= c.cy + c.cell);
        let tip = canvas.parentElement.querySelector('.chart-tooltip');
        if (!tip) {
            tip = document.createElement('div');
            tip.className = 'chart-tooltip';
            canvas.parentElement.style.position = 'relative';
            canvas.parentElement.appendChild(tip);
        }
        if (hit) {
            tip.style.display = 'block';
            tip.style.left = `${e.offsetX + 12}px`;
            tip.style.top = `${e.offsetY + 12}px`;
            tip.innerHTML = `${hit.dateStr}: <strong>${hit.count} check-in${hit.count !== 1 ? 's' : ''}</strong>`;
        } else {
            tip.style.display = 'none';
        }
    };
    canvas.onmouseleave = function () {
        const tip = canvas.parentElement.querySelector('.chart-tooltip');
        if (tip) tip.style.display = 'none';
    };
}

function renderPieChart(canvasId, series = [], colors = []) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const W = canvas.width, H = canvas.height;
    ctx.clearRect(0, 0, W, H);
    const total = series.reduce((s, d) => s + (Number(d.total) || 0), 0);
    if (!total) {
        ctx.fillStyle = '#9ca3af'; ctx.font = '13px Arial';
        ctx.fillText('No data available', 16, H / 2); return;
    }
    const palette = colors.length ? colors : ['#166534','#dc2626','#0369a1','#b45309','#7c3aed','#0891b2','#15803d','#d97706','#0f766e','#9d174d'];
    const cx = W * 0.36, cy = H / 2, r = Math.min(cx - 10, cy - 10);
    const innerR = r * 0.42; // donut hole
    let angle = -Math.PI / 2;
    const segments = [];
    series.forEach((item, i) => {
        const slice = (Number(item.total) / total) * 2 * Math.PI;
        const clr = palette[i % palette.length];
        ctx.beginPath();
        ctx.moveTo(cx + innerR * Math.cos(angle), cy + innerR * Math.sin(angle));
        ctx.arc(cx, cy, r, angle, angle + slice);
        ctx.arc(cx, cy, innerR, angle + slice, angle, true);
        ctx.closePath();
        ctx.fillStyle = clr;
        ctx.fill();
        ctx.strokeStyle = '#fff'; ctx.lineWidth = 2; ctx.stroke();
        segments.push({ startAngle: angle, endAngle: angle + slice, color: clr, data: item, cx, cy, r, innerR });
        angle += slice;
    });
    // Legend
    const legendX = cx + r + 18;
    let legendY = Math.max(14, cy - (series.length * 11));
    series.forEach((item, i) => {
        const clr = palette[i % palette.length];
        ctx.fillStyle = clr;
        ctx.beginPath(); ctx.roundRect(legendX, legendY, 12, 12, 3); ctx.fill();
        ctx.fillStyle = '#374151'; ctx.font = '10.5px Arial'; ctx.textAlign = 'left';
        const pct = ((Number(item.total) / total) * 100).toFixed(0);
        ctx.fillText(`${String(item.label).slice(0, 13)} ${pct}%`, legendX + 16, legendY + 10);
        legendY += 20;
    });
    // Tooltip
    canvas.onmousemove = function(e) {
        const rect = canvas.getBoundingClientRect();
        const mx = ((e.clientX - rect.left) / rect.width) * W;
        const my = ((e.clientY - rect.top) / rect.height) * H;
        const dx = mx - cx, dy = my - cy, dist = Math.hypot(dx, dy);
        let tip = canvas.parentElement.querySelector('.chart-tooltip');
        if (!tip) {
            tip = document.createElement('div'); tip.className = 'chart-tooltip';
            canvas.parentElement.style.position = 'relative';
            canvas.parentElement.appendChild(tip);
        }
        if (dist >= innerR && dist <= r) {
            let a = Math.atan2(dy, dx);
            if (a < -Math.PI / 2) a += 2 * Math.PI;
            const hit = segments.find(s => a >= s.startAngle && a < s.endAngle);
            if (hit) {
                const fmt = typeof Utils !== 'undefined' && Utils.formatCurrency ? Utils.formatCurrency(hit.data.total) : hit.data.total;
                const pct = ((Number(hit.data.total) / total) * 100).toFixed(1);
                tip.style.display = 'block';
                tip.style.left = `${e.offsetX + 12}px`;
                tip.style.top = `${e.offsetY + 12}px`;
                tip.innerHTML = `${hit.data.label}: ${fmt} (${pct}%)`;
                return;
            }
        }
        tip.style.display = 'none';
    };
    canvas.onmouseleave = () => { const t = canvas.parentElement.querySelector('.chart-tooltip'); if (t) t.style.display = 'none'; };
}

function loadActivityAnalytics() {
    const adminUsername = document.getElementById('activityUserSearch')?.value || '';
    const logAction = document.getElementById('activityActionSearch')?.value || '';
    const startDate = document.getElementById('activityStartDate')?.value || '';
    const endDate = document.getElementById('activityEndDate')?.value || '';

    fetch(`api/admin-activity.php?action=analytics&admin_username=${encodeURIComponent(adminUsername)}&log_action=${encodeURIComponent(logAction)}&start_date=${encodeURIComponent(startDate)}&end_date=${encodeURIComponent(endDate)}`)
        .then(res => res.json())
        .then(result => {
            if (!result.success) throw new Error(result.message || 'Failed to load analytics');
            const analytics = result.data || {};
            const host = document.getElementById('activityAnalyticsContainer');
            if (!host) return;

            host.innerHTML = `
                <div class="activity-analytics-grid">
                    <div class="chart-card">
                        <div class="chart-card-header"><h3>Daily Activity</h3><small>Day-by-day trend</small></div>
                        <canvas id="activityDailyChart" width="520" height="220"></canvas>
                    </div>
                    <div class="chart-card">
                        <div class="chart-card-header"><h3>Weekly Activity</h3><small>Week-by-week trend</small></div>
                        <canvas id="activityWeeklyChart" width="520" height="220"></canvas>
                    </div>
                    <div class="chart-card">
                        <div class="chart-card-header"><h3>Monthly Activity</h3><small>Month-by-month trend</small></div>
                        <canvas id="activityMonthlyChart" width="520" height="220"></canvas>
                    </div>
                    <div class="chart-card">
                        <div class="chart-card-header"><h3>Staff Contribution</h3><small>Who is doing most actions</small></div>
                        <canvas id="activityStaffChart" width="520" height="220"></canvas>
                    </div>
                    <div class="chart-card">
                        <div class="chart-card-header"><h3>Action Breakdown</h3><small>Most common action types</small></div>
                        <canvas id="activityActionChart" width="520" height="220"></canvas>
                    </div>
                </div>
            `;

            renderSimpleLineChart('activityDailyChart', analytics.daily || [], '#166534');
            renderSimpleLineChart('activityWeeklyChart', analytics.weekly || [], '#0369a1');
            renderSimpleLineChart('activityMonthlyChart', analytics.monthly || [], '#b45309');
            renderSimpleBarChart('activityStaffChart', analytics.staff || [], '#166534');
            renderSimpleBarChart('activityActionChart', analytics.actions || [], '#7c3aed');
        })
        .catch(err => {
            const host = document.getElementById('activityAnalyticsContainer');
            if (host) host.innerHTML = `<div class="error">${err.message}</div>`;
        });
}

window.chartVisibility = window.chartVisibility || {};

function toggleChartDataset(canvasId, label, buttonEl = null) {
    if (!window.chartVisibility[canvasId]) window.chartVisibility[canvasId] = {};
    window.chartVisibility[canvasId][label] = !window.chartVisibility[canvasId][label];

    if (buttonEl) {
        buttonEl.classList.toggle('is-muted', !!window.chartVisibility[canvasId][label]);
    }

    const config = window.chartMeta?.[canvasId];
    if (!config) return;

    renderReportCharts([config]);
}

function renderChartLegend(items = [], canvasId = '') {
    if (!items.length) return '';
    return `
        <div class="chart-legend">
            ${items.map(item => {
                const hidden = window.chartVisibility[canvasId]?.[item.label];
                return `
                    <button type="button" class="chart-legend-item ${hidden ? 'is-muted' : ''}" onclick="toggleChartDataset('${canvasId}', '${item.label.replace(/'/g, "\\'")}', this)">
                        <span class="chart-legend-swatch" style="background:${item.color}"></span>
                        <span>${item.label}</span>
                    </button>
                `;
            }).join('')}
        </div>
    `;
}

function renderMultiLineChart(canvasId, datasets = []) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const width = canvas.width;
    const height = canvas.height;
    ctx.clearRect(0, 0, width, height);

    const visibleDatasets = datasets.filter(dataset => !window.chartVisibility?.[canvasId]?.[dataset.label]);
    const labels = visibleDatasets[0]?.series?.map(item => item.label) || datasets[0]?.series?.map(item => item.label) || [];
    if (!labels.length) {
        ctx.fillStyle = '#6b7280';
        ctx.font = '14px Arial';
        ctx.fillText('No data available', 20, 30);
        return;
    }

    const padding = 30;
    const allValues = visibleDatasets.flatMap(ds => ds.series.map(item => Number(item.total) || 0));
    const maxValue = Math.max(1, ...allValues);
    const stepX = labels.length > 1 ? (width - padding * 2) / (labels.length - 1) : 0;

    drawChartAxes(ctx, width, height, padding, maxValue);

    const tooltipPoints = [];
    visibleDatasets.forEach(dataset => {
        ctx.strokeStyle = dataset.color;
        ctx.lineWidth = 2.5;
        ctx.beginPath();

        let lastPoint = null;
        dataset.series.forEach((item, index) => {
            const x = padding + index * stepX;
            const y = height - padding - ((Number(item.total) || 0) / maxValue) * (height - padding * 2);
            tooltipPoints.push({ x, y, data: { ...item, dataset: dataset.label } });
            lastPoint = { x, y, item };
            if (index === 0) {
                ctx.moveTo(x, y);
            } else {
                const prevX = padding + (index - 1) * stepX;
                const prevY = height - padding - ((Number(dataset.series[index - 1].total) || 0) / maxValue) * (height - padding * 2);
                const cpX = (prevX + x) / 2;
                ctx.bezierCurveTo(cpX, prevY, cpX, y, x, y);
            }
        });
        ctx.stroke();

        if (lastPoint) {
            dataset._lastPoint = lastPoint;
        }
    });

    const placedLabels = [];
    visibleDatasets.forEach(dataset => {
        const lastPoint = dataset._lastPoint;
        if (!lastPoint) return;

        let labelX = Math.min(lastPoint.x + 8, width - 120);
        let labelY = Math.max(lastPoint.y - 6, 14);

        while (placedLabels.some(y => Math.abs(y - labelY) < 14)) {
            labelY += 14;
            if (labelY > height - 20) {
                labelY = Math.max(14, lastPoint.y - 20);
                break;
            }
        }
        placedLabels.push(labelY);

        ctx.fillStyle = dataset.color;
        ctx.font = 'bold 11px Arial';
        const valueText = `${dataset.label}: ${Number(lastPoint.item.total || 0).toFixed(0)}`;
        ctx.fillText(valueText, labelX, labelY);
    });

    labels.forEach((label, index) => {
        const x = padding + index * stepX;
        if (index === 0 || index === labels.length - 1 || index % Math.ceil(labels.length / 4) === 0) {
            ctx.fillStyle = '#6b7280';
            ctx.font = '10px Arial';
            ctx.fillText(String(label).slice(0, 8), x - 12, height - 10);
        }
    });

    attachChartTooltip(canvas, tooltipPoints, item => `${item.dataset}<br>${item.label}: ${item.total}`);
}

function renderReportCharts(configs = []) {
    setTimeout(() => {
        configs.forEach(config => {
            window.chartMeta = window.chartMeta || {};
            window.chartMeta[config.id] = config;
            if (config.type === 'multi-line') {
                renderMultiLineChart(config.id, config.datasets || []);
            } else if (config.type === 'line') {
                renderSimpleLineChart(config.id, config.series || [], config.color);
            } else {
                renderSimpleBarChart(config.id, config.series || [], config.color);
            }
        });
    }, 0);
}

function renderAnalyticsBlock(title, subtitle, chartId, series = [], type = 'line', color = '#166534') {
    const empty = !series || !series.length;
    return `
        <div class="chart-card">
            <div class="chart-card-header">
                <h3>${title}</h3>
                <small>${subtitle}</small>
            </div>
            <canvas id="${chartId}" width="520" height="220"></canvas>
            ${empty ? '<div class="activity-muted" style="margin-top:0.75rem;">No chart data available yet.</div>' : ''}
        </div>
    `;
}

function renderRangeSelector(sectionKey, activeRange = '30d') {
    const ranges = [
        ['7d', '7D'],
        ['30d', '30D'],
        ['3m', '3M'],
        ['6m', '6M'],
        ['12m', '12M']
    ];
    return `
        <div class="analytics-range-selector">
            ${ranges.map(([value, label]) => `<button class="btn ${activeRange === value ? 'btn-primary' : 'btn-secondary'} btn-sm" onclick="setAnalyticsRange('${sectionKey}', '${value}')">${label}</button>`).join('')}
        </div>
    `;
}

window.analyticsRanges = window.analyticsRanges || {
    reports: '30d',
    payments: '30d',
    attendance: '30d',
    expenses: '30d'
};

function setAnalyticsRange(sectionKey, range) {
    window.analyticsRanges[sectionKey] = range;
    if (sectionKey === 'payments') loadPaymentsAnalytics();
    else if (sectionKey === 'attendance') loadAttendanceAnalytics();
    else if (sectionKey === 'expenses') loadExpensesAnalytics();
    else if (sectionKey === 'reports') {
        const activeCard = document.querySelector('.report-card.active-report');
        if (activeCard?.dataset?.report) generateReport(activeCard.dataset.report);
    }
}

function loadActivityLog() {
    const html = `
        <div class="members-section activity-log-section">
            ${renderSectionGuideCard({
                chip: 'Activity Help',
                title: 'See which staff member did what',
                description: 'This log helps admin check member updates, payments, expenses, and staff changes.',
                steps: [
                    'Search by username if you want one staff member only.',
                    'Use action type to narrow the list.',
                    'Newest entries show at the top.'
                ]
            })}
            <div class="activity-toolbar">
                <div class="section-actions activity-filters">
                    <input type="text" id="activityUserSearch" placeholder="Search by staff username" class="search-input">
                    <select id="activityActionSearch" class="search-input form-control">
                        <option value="">All actions</option>
                        <option value="member_created">Member Created</option>
                        <option value="member_updated">Member Updated</option>
                        <option value="member_deleted">Member Deleted</option>
                        <option value="member_due_date_updated">Due Date Updated</option>
                        <option value="payment_recorded">Payment Recorded</option>
                        <option value="expense_created">Expense Added</option>
                        <option value="expense_updated">Expense Updated</option>
                        <option value="expense_deleted">Expense Deleted</option>
                        <option value="staff_created">Staff Created</option>
                        <option value="staff_updated">Staff Updated</option>
                        <option value="staff_deleted">Staff Deleted</option>
                    </select>
                    <input type="date" id="activityStartDate" class="search-input">
                    <input type="date" id="activityEndDate" class="search-input">
                    <button class="btn btn-primary" onclick="loadActivityLogTable(1)">Refresh</button>
                </div>
                <div id="activitySummaryCards" class="activity-summary-cards"></div>
            </div>
            <div id="activityAnalyticsContainer"></div>
            <div id="activityLogContainer"></div>
        </div>
    `;
    document.getElementById('contentBody').innerHTML = html;
    document.getElementById('activityUserSearch')?.addEventListener('input', Utils.debounce(() => loadActivityLogTable(1), 300));
    document.getElementById('activityActionSearch')?.addEventListener('change', () => { loadActivityLogTable(1); loadActivityAnalytics(); });
    document.getElementById('activityStartDate')?.addEventListener('change', () => { loadActivityLogTable(1); loadActivityAnalytics(); });
    document.getElementById('activityEndDate')?.addEventListener('change', () => { loadActivityLogTable(1); loadActivityAnalytics(); });
    loadActivityLogTable(1);
    loadActivityAnalytics();
}

function loadActivityLogTable(page = 1) {
    const adminUsername = document.getElementById('activityUserSearch')?.value || '';
    const logAction = document.getElementById('activityActionSearch')?.value || '';
    const startDate = document.getElementById('activityStartDate')?.value || '';
    const endDate = document.getElementById('activityEndDate')?.value || '';
    fetch(`api/admin-activity.php?action=list&page=${page}&limit=20&admin_username=${encodeURIComponent(adminUsername)}&log_action=${encodeURIComponent(logAction)}&start_date=${encodeURIComponent(startDate)}&end_date=${encodeURIComponent(endDate)}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) throw new Error(data.message || 'Failed to load activity log');
            const rows = data.data || [];
            const pagination = data.pagination || { page: 1, pages: 1, limit: 20, total: 0 };
            const startIndex = ((pagination.page || 1) - 1) * (pagination.limit || 20);

            const uniqueUsers = new Set(rows.map(row => row.admin_username).filter(Boolean)).size;
            const actionCounts = rows.reduce((acc, row) => {
                acc[row.action] = (acc[row.action] || 0) + 1;
                return acc;
            }, {});
            const topAction = Object.entries(actionCounts).sort((a, b) => b[1] - a[1])[0];

            const staffCounts = rows.reduce((acc, row) => {
                const key = row.admin_username || 'Unknown';
                acc[key] = (acc[key] || 0) + 1;
                return acc;
            }, {});
            const maxStaffCount = Math.max(1, ...Object.values(staffCounts));

            const summaryEl = document.getElementById('activitySummaryCards');
            if (summaryEl) {
                summaryEl.innerHTML = `
                    <div class="activity-summary-card">
                        <span class="activity-summary-label">Shown Rows</span>
                        <strong>${rows.length}</strong>
                        <small>On this page</small>
                    </div>
                    <div class="activity-summary-card">
                        <span class="activity-summary-label">Total Logs</span>
                        <strong>${pagination.total || 0}</strong>
                        <small>All matching entries</small>
                    </div>
                    <div class="activity-summary-card">
                        <span class="activity-summary-label">Staff Seen</span>
                        <strong>${uniqueUsers}</strong>
                        <small>Users in this page</small>
                    </div>
                    <div class="activity-summary-card">
                        <span class="activity-summary-label">Top Action</span>
                        <strong>${topAction ? getActivityActionLabel(topAction[0]) : 'None'}</strong>
                        <small>${topAction ? topAction[1] + ' time(s)' : 'No actions yet'}</small>
                    </div>
                    <div class="activity-summary-card activity-chart-card">
                        <span class="activity-summary-label">Staff-wise Activity</span>
                        <div class="activity-mini-chart">
                            ${Object.entries(staffCounts).sort((a, b) => b[1] - a[1]).map(([name, count]) => `
                                <div class="activity-bar-row">
                                    <span class="activity-bar-label">${name}</span>
                                    <div class="activity-bar-track">
                                        <div class="activity-bar-fill" style="width:${Math.max(8, (count / maxStaffCount) * 100)}%"></div>
                                    </div>
                                    <span class="activity-bar-value">${count}</span>
                                </div>
                            `).join('') || '<span class="activity-muted">No staff activity yet</span>'}
                        </div>
                    </div>
                `;
            }

            document.getElementById('activityLogContainer').innerHTML = rows.length ? `
                <div class="activity-log-grid">
                    ${rows.map((row, idx) => `
                        <article class="activity-card" onclick='openActivityModal(${JSON.stringify(row).replace(/'/g, '&apos;')})' role="button" tabindex="0">
                            <div class="activity-card-top">
                                <div>
                                    <span class="activity-index">#${startIndex + idx + 1}</span>
                                    <h3>${getActivityActionLabel(row.action)}</h3>
                                </div>
                                <span class="activity-badge ${getActivityActionClass(row.action)}">${row.action}</span>
                            </div>
                            <div class="activity-meta-grid">
                                <div class="activity-meta-item">
                                    <span class="activity-meta-label">Staff</span>
                                    <strong>${row.admin_username || '-'}</strong>
                                </div>
                                <div class="activity-meta-item">
                                    <span class="activity-meta-label">Time</span>
                                    <strong>${row.created_at || '-'}</strong>
                                </div>
                                <div class="activity-meta-item">
                                    <span class="activity-meta-label">Target</span>
                                    <strong>${row.target_type || '-'}</strong>
                                </div>
                                <div class="activity-meta-item">
                                    <span class="activity-meta-label">Target ID</span>
                                    <strong>${row.target_id || '-'}</strong>
                                </div>
                            </div>
                            <div class="activity-details-wrap">
                                <div class="activity-details-title">Details</div>
                                <div class="activity-details-pills">
                                    ${formatActivityDetails(row.details)}
                                </div>
                            </div>
                        </article>
                    `).join('')}
                </div>
                <div class="activity-table-wrap">
                    <table class="data-table activity-table">
                        <thead>
                            <tr>
                                <th>#</th><th>Time</th><th>Staff</th><th>Action</th><th>Target</th><th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${rows.map((row, idx) => `
                                <tr onclick='openActivityModal(${JSON.stringify(row).replace(/'/g, '&apos;')})' style="cursor:pointer;">
                                    <td data-label="#">${startIndex + idx + 1}</td>
                                    <td data-label="Time">${row.created_at || '-'}</td>
                                    <td data-label="Staff">${row.admin_username || '-'}</td>
                                    <td data-label="Action"><span class="activity-badge ${getActivityActionClass(row.action)}">${getActivityActionLabel(row.action)}</span></td>
                                    <td data-label="Target">${row.target_type || '-'} ${row.target_id || ''}</td>
                                    <td data-label="Details"><div class="activity-details-pills">${formatActivityDetails(row.details)}</div></td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
                ${pagination.pages > 1 ? `
                    <div class="pagination activity-pagination">
                        <button class="btn btn-secondary" ${pagination.page === 1 ? 'disabled' : ''} onclick="loadActivityLogTable(${pagination.page - 1})">Previous</button>
                        <span>Page ${pagination.page} of ${pagination.pages}</span>
                        <button class="btn btn-secondary" ${pagination.page === pagination.pages ? 'disabled' : ''} onclick="loadActivityLogTable(${pagination.page + 1})">Next</button>
                    </div>
                ` : ''}
            ` : '<div class="empty-state"><strong>No activity found</strong>No admin/staff action has been logged yet.</div>';
        })
        .catch(err => {
            document.getElementById('activityLogContainer').innerHTML = `<div class="error">${err.message}</div>`;
        });
}

function loadDueFees() {
    const html = `
        <div class="due-fees-section">
            ${renderSectionGuideCard({
                chip: 'Due List Help',
                title: 'Members who still need to pay',
                description: 'This page helps you find unpaid members fast. Use Update Due when someone pays at the desk or you want to correct dues.',
                steps: [
                    'Search by member code, name, or phone.',
                    'Use the gender filter only if you want a shorter list.',
                    'The red amount shows how much the member still owes.'
                ]
            })}
            <div class="section-header">
                <h2>Members Who Need to Pay</h2>
                <div class="section-actions">
                    <input type="text" id="dueFeeSearch" placeholder="Search by code, name, or phone" class="search-input">
                    <select id="dueFeeGenderFilter" class="search-input" style="width: auto;">
                        <option value="all">All</option>
                        <option value="men">Men only</option>
                        <option value="women">Women only</option>
                    </select>
                </div>
            </div>
            <div id="dueFeesSummary" style="margin-bottom: 1.5rem;"></div>
            <div id="dueFeesAnalyticsContainer" style="margin-bottom:1.5rem;"></div>
            <div id="dueFeesTableContainer"></div>
        </div>
    `;
    document.getElementById('contentBody').innerHTML = html;

    // Setup search
    const searchInput = document.getElementById('dueFeeSearch');
    if (searchInput) {
        searchInput.addEventListener('input', Utils.debounce(function () {
            loadDueFeesTable();
        }, 300));
    }

    // Setup gender filter
    const genderFilter = document.getElementById('dueFeeGenderFilter');
    if (genderFilter) {
        genderFilter.addEventListener('change', function () {
            loadDueFeesTable();
        });
    }

    loadDueFeesAnalytics();
    loadDueFeesTable();
}

function loadDueFeesAnalytics() {
    const container = document.getElementById('dueFeesAnalyticsContainer');
    if (!container) return;

    fetch('api/reports.php?action=defaulters')
        .then(res => res.json())
        .then(result => {
            if (!result.success) throw new Error(result.message || 'Failed to load due fee analytics');
            const data = result.data || {};
            container.innerHTML = `
                <div class="activity-analytics-grid">
                    ${renderAnalyticsBlock('Gender Split', 'Who has unpaid dues', 'dueFeesGenderChart', data.charts?.gender_split || [], 'bar', '#0369a1')}
                    ${renderAnalyticsBlock('Overdue Bands', 'How late members are', 'dueFeesBandsChart', data.charts?.overdue_bands || [], 'bar', '#b45309')}
                    ${renderAnalyticsBlock('Top Defaulters', 'Highest due amounts', 'dueFeesTopChart', data.charts?.top_defaulters || [], 'bar', '#dc2626')}
                    ${renderAnalyticsBlock('Dues Trend', 'Outstanding dues over time', 'dueFeesTrendChart', data.charts?.dues_trend || [], 'line', '#7c3aed')}
                </div>
            `;
            renderReportCharts([
                { id: 'dueFeesGenderChart', type: 'bar', series: data.charts?.gender_split || [], color: '#0369a1' },
                { id: 'dueFeesBandsChart', type: 'bar', series: data.charts?.overdue_bands || [], color: '#b45309' },
                { id: 'dueFeesTopChart', type: 'bar', series: data.charts?.top_defaulters || [], color: '#dc2626' },
                { id: 'dueFeesTrendChart', type: 'line', series: data.charts?.dues_trend || [], color: '#7c3aed' }
            ]);
        })
        .catch(err => {
            container.innerHTML = `<div class="error">${err.message}</div>`;
        });
}

function loadDueFeesTable(page = 1) {
    const search = document.getElementById('dueFeeSearch')?.value || '';
    const gender = document.getElementById('dueFeeGenderFilter')?.value || 'all';
    const limit = 50;

    // Cancel previous in-flight request for due fees
    if (activeRequests['dueFees']) {
        activeRequests['dueFees'].abort();
    }
    const abortController = new AbortController();
    activeRequests['dueFees'] = abortController;

    fetch(`api/get-due-fees.php?gender=${gender}&page=${page}&limit=${limit}&search=${encodeURIComponent(search)}`, { signal: abortController.signal })
        .then(async res => {
            if (!res.ok) {
                let errorMessage = 'Failed to load due fees';
                try {
                    const errorData = await res.json();
                    errorMessage = errorData.message || errorMessage;
                } catch (e) {
                    errorMessage = `Error ${res.status}: ${res.statusText || 'Server error'}`;
                }
                throw new Error(errorMessage);
            }

            const contentType = res.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                throw new Error('Invalid response format from server');
            }

            const text = await res.text();
            if (!text || text.trim() === '') {
                throw new Error('Empty response from server');
            }

            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('Invalid JSON response:', text);
                throw new Error('Invalid JSON response from server');
            }
        })
        .then(data => {
            if (abortController.signal.aborted) return;
            if (data.success) {
                const normalizedMembers = (data.data || []).map(normalizeMemberStatus);
                renderDueFeesSummary(data.summary);
                renderDueFeesTable(normalizedMembers, data.pagination);
            } else {
                document.getElementById('dueFeesTableContainer').innerHTML =
                    '<div class="error">Failed to load due fees</div>';
            }
        })
        .catch(err => {
            console.error('Due fees error:', err);
            document.getElementById('dueFeesTableContainer').innerHTML =
                `<div class="error">Error loading due fees: ${err.message}</div>`;
        });
}

function renderDueFeesSummary(summary) {
    const html = `
        <div class="dashboard-stats">
            <div class="stat-card">
                <h3>Total Members with Due</h3>
                <p style="font-size: 2rem; font-weight: bold; color: var(--secondary-color);">
                    ${summary.total_members_with_due || 0}
                </p>
            </div>
            <div class="stat-card">
                <h3>Total Due Amount</h3>
                <p style="font-size: 2rem; font-weight: bold; color: #e74c3c;">
                    ${Utils.formatCurrency(summary.total_due_amount || 0)}
                </p>
            </div>
            <div class="stat-card">
                <h3>Overdue Members</h3>
                <p style="font-size: 2rem; font-weight: bold; color: #f39c12;">
                    ${summary.overdue_members || 0}
                </p>
            </div>
            <div class="stat-card">
                <h3>Due Today</h3>
                <p style="font-size: 2rem; font-weight: bold; color: #3498db;">
                    ${summary.due_today || 0}
                </p>
            </div>
        </div>
    `;
    document.getElementById('dueFeesSummary').innerHTML = html;
}

function renderDueFeesTable(members, pagination) {
    if (!members || members.length === 0) {
        document.getElementById('dueFeesTableContainer').innerHTML =
            '<div class="empty-state"><strong>No unpaid members found</strong>Good news. Nobody is showing as unpaid in the current filter.</div>';
        return;
    }

    const currentPage = pagination ? (parseInt(pagination.page) || 1) : 1;
    const totalPages = pagination ? (parseInt(pagination.total_pages) || 1) : 1;
    const limit = pagination ? (parseInt(pagination.limit) || 50) : 50;
    const startIndex = (currentPage - 1) * limit;

    const html = `
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Gender</th>
                    <th>Phone</th>
                    <th>Due Amount</th>
                    <th>Next Fee Due Date</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                ${members.map((m, idx) => `
                    <tr>
                        <td data-label="#">${startIndex + idx + 1}</td>
                        <td data-label="Code">${m.member_code}</td>
                        <td data-label="Name"><strong>${m.name}</strong></td>
                        <td data-label="Gender">${m.gender === 'men' ? '👨 Men' : '👩 Women'}</td>
                        <td data-label="Phone">${m.phone}</td>
                        <td data-label="Due Amount"><strong style="color: #e74c3c;">${Utils.formatCurrency(m.total_due_amount || 0)}</strong></td>
                        <td data-label="Next Fee Due">${m.next_fee_due_date ? Utils.formatDate(m.next_fee_due_date) : 'N/A'}</td>
                        <td data-label="Status"><span class="badge ${(m.calculated_status || m.status) === 'active' ? 'badge-success' : 'badge-secondary'}">${m.calculated_status || m.status}</span></td>
                        <td data-label="Actions">
                            ${isAdminUser() ? `
                                <button class="btn btn-sm btn-primary" onclick="showUpdateDueFeeModal(${m.id}, '${m.gender}', ${m.total_due_amount || 0}, '${m.name}')">
                                    Receive / Update
                                </button>
                            ` : '<span style="color:#6b7280;">Read only</span>'}
                        </td>
                    </tr>
                `).join('')}
            </tbody>
        </table>
        ${pagination && totalPages > 1 ? `
            <div class="pagination" style="margin-top: 1.5rem; display: flex; justify-content: center; align-items: center; gap: 1rem; flex-wrap: wrap;">
                <button class="btn btn-secondary" ${currentPage <= 1 ? 'disabled' : ''} onclick="loadDueFeesTable(${currentPage - 1})">
                    Previous
                </button>
                <span>Page</span>
                <input type="number" id="dueFeesPageInput" min="1" max="${totalPages}" value="${currentPage}" style="width: 60px; padding: 0.25rem; text-align: center; border: 1px solid #ddd; border-radius: 4px;" onchange="const page = parseInt(this.value) || 1; if (page >= 1 && page <= ${totalPages}) loadDueFeesTable(page); else this.value = ${currentPage};" onkeypress="if(event.key === 'Enter') { const page = parseInt(this.value) || 1; if (page >= 1 && page <= ${totalPages}) loadDueFeesTable(page); else this.value = ${currentPage}; }">
                <span>of ${totalPages}</span>
                <button class="btn btn-secondary" ${currentPage >= totalPages ? 'disabled' : ''} onclick="loadDueFeesTable(${currentPage + 1})">
                    Next
                </button>
            </div>
        ` : ''}
    `;
    document.getElementById('dueFeesTableContainer').innerHTML = html;
}

function showUpdateDueFeeModal(memberId, gender, currentDueAmount, memberName) {
    if (!requireAdminAccess('update due amounts')) return;

    const html = `
        <div class="modal" id="updateDueFeeModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>Update Unpaid Amount - ${memberName}</h2>
                    <button class="modal-close" onclick="closeUpdateDueFeeModal()">&times;</button>
                </div>
                <form id="updateDueFeeForm" class="modal-body">
                    <input type="hidden" id="dueFeeMemberId" value="${memberId}">
                    <input type="hidden" id="dueFeeGender" value="${gender}">

                    <div class="form-group">
                        <label>Current unpaid amount:</label>
                        <strong style="font-size: 1.2rem; color: #e74c3c;">${Utils.formatCurrency(currentDueAmount)}</strong>
                    </div>

                    <div class="form-group">
                        <label>What do you want to do? *</label>
                        <select id="dueFeeAction" name="action" required>
                            <option value="update">Set a new unpaid amount</option>
                            <option value="add">Add more unpaid amount</option>
                            <option value="clear">Clear all unpaid amount (set to 0)</option>
                        </select>
                    </div>

                    <div class="form-group" id="dueFeeAmountGroup">
                        <label>Amount *</label>
                        <input type="number" step="0.01" id="dueFeeAmount" name="amount" value="${currentDueAmount}" min="0" required>
                        <small>Enter the amount for the option you selected above.</small>
                    </div>

                    <div class="form-group">
                        <div id="dueFeePreview" style="background: var(--bg-secondary); color: var(--text-color); padding: 1rem; border-radius: 5px; margin-top: 1rem; border: 1px solid var(--border-color);">
                            <strong style="color: var(--text-color);">Preview:</strong> <span style="color: var(--text-secondary);">New unpaid amount will be: <span id="previewAmount" style="color: var(--text-color); font-weight: bold;">${Utils.formatCurrency(currentDueAmount)}</span></span>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeUpdateDueFeeModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Unpaid Amount</button>
                    </div>
                </form>
            </div>
        </div>
    `;

    document.body.insertAdjacentHTML('beforeend', html);

    const form = document.getElementById('updateDueFeeForm');
    const actionSelect = document.getElementById('dueFeeAction');
    const amountInput = document.getElementById('dueFeeAmount');
    const amountGroup = document.getElementById('dueFeeAmountGroup');
    const previewDiv = document.getElementById('previewAmount');

    // Update preview when action or amount changes
    function updatePreview() {
        const action = actionSelect.value;
        const currentAmount = parseFloat(currentDueAmount) || 0;
        const inputAmount = parseFloat(amountInput.value) || 0;
        let newAmount = 0;

        if (action === 'clear') {
            newAmount = 0;
        } else if (action === 'add') {
            newAmount = currentAmount + inputAmount;
        } else {
            newAmount = inputAmount;
        }

        previewDiv.textContent = Utils.formatCurrency(newAmount);
    }

    actionSelect.addEventListener('change', function () {
        if (this.value === 'clear') {
            amountGroup.style.display = 'none';
            amountInput.required = false;
        } else {
            amountGroup.style.display = 'block';
            amountInput.required = true;
            if (this.value === 'add') {
                amountInput.value = 0;
                amountInput.placeholder = 'Amount to add';
            } else {
                amountInput.value = currentDueAmount;
                amountInput.placeholder = 'New unpaid amount';
            }
        }
        updatePreview();
    });

    amountInput.addEventListener('input', updatePreview);

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        saveDueFeeUpdate();
    });
}

function closeUpdateDueFeeModal() {
    const modal = document.getElementById('updateDueFeeModal');
    if (modal) modal.remove();
}

function saveDueFeeUpdate() {
    const memberId = document.getElementById('dueFeeMemberId').value;
    const gender = document.getElementById('dueFeeGender').value;
    const action = document.getElementById('dueFeeAction').value;
    const amount = parseFloat(document.getElementById('dueFeeAmount').value) || 0;

    if (action !== 'clear' && amount < 0) {
        Utils.showNotification('Amount cannot be negative', 'error');
        return;
    }

    const dueFeeData = {
        member_id: memberId,
        gender: gender,
        action: action,
        due_amount: action === 'clear' ? 0 : amount
    };

    fetch('api/update-due-fee.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(dueFeeData)
    })
        .then(async res => {
            if (!res.ok) {
                let errorMessage = 'Failed to update unpaid amount';
                try {
                    const errorData = await res.json();
                    errorMessage = errorData.message || errorMessage;
                } catch (e) {
                    errorMessage = `Error ${res.status}: ${res.statusText || 'Server error'}`;
                }
                throw new Error(errorMessage);
            }

            const contentType = res.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                throw new Error('Invalid response format from server');
            }

            const text = await res.text();
            if (!text || text.trim() === '') {
                throw new Error('Empty response from server');
            }

            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('Invalid JSON response:', text);
                throw new Error('Invalid JSON response from server');
            }
        })
        .then(data => {
            if (data.success) {
                const message = data.message || 'Unpaid amount updated successfully';
                if (data.payment_recorded) {
                    Utils.showNotification(message + ' Payment recorded in member profile.', 'success');
                } else {
                    Utils.showNotification(message, 'success');
                }
                closeUpdateDueFeeModal();

                // Refresh all tables with a small delay to ensure database transaction is complete
                setTimeout(() => {
                    loadDueFeesTable();
                    // Refresh members table to show updated due amounts
                    if (currentSection === 'members') {
                        loadMembersTable();
                    }
                    // Refresh payments table to show updated payment records
                    if (document.getElementById('paymentsTableContainer')) {
                        loadPaymentsTable();
                    }
                    // If on dashboard, refresh it too to update revenue
                    if (document.getElementById('dashboard-stats')) {
                        loadDashboard();
                    }
                }, 500);
            } else {
                Utils.showNotification(data.message || 'Failed to update unpaid amount', 'error');
            }
        })
        .catch(err => {
            console.error('Due fee update error:', err);
            Utils.showNotification(err.message || 'Error updating unpaid amount', 'error');
        });
}

function loadExpenses() {
    // Cancel any existing expenses requests
    if (activeRequests['expensesTable']) {
        activeRequests['expensesTable'].abort();
        delete activeRequests['expensesTable'];
    }
    if (activeRequests['expensesSummary']) {
        activeRequests['expensesSummary'].abort();
        delete activeRequests['expensesSummary'];
    }
    if (activeRequests['expenseCategories']) {
        activeRequests['expenseCategories'].abort();
        delete activeRequests['expenseCategories'];
    }

    const html = `
        <div class="expenses-section">
            ${renderSectionGuideCard({
                chip: 'Expenses Help',
                title: 'Record money spent by the gym',
                description: 'Use this only when the gym pays money out, like rent, electricity, repairs, or supplies.',
                steps: [
                    'Use This Month for normal daily work.',
                    'Search by what was paid for or by category.',
                    'Add a short note so future staff understand the expense.'
                ]
            })}
            <div class="section-header">
                <h2>Money Spent</h2>
                <div class="section-actions">
                    <div style="display: flex; gap: 0.5rem; align-items: center; margin-right: 0.5rem;">
                        <button class="btn ${expensesViewMode === 'current' ? 'btn-primary' : 'btn-secondary'}" id="expenseViewCurrentBtn">This Month</button>
                        <button class="btn ${expensesViewMode === 'history' ? 'btn-primary' : 'btn-secondary'}" id="expenseViewHistoryBtn">Older Expenses</button>
                    </div>
                    <div id="expenseHistorySelector" style="display: ${expensesViewMode === 'history' ? 'flex' : 'none'}; gap: 0.5rem; align-items: center; margin-right: 0.5rem;">
                        <select id="expenseMonth" class="search-input" style="width: auto;">
                            ${Array.from({ length: 12 }, (_, i) => {
        const month = i + 1;
        const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        return `<option value="${month}" ${month === expensesSelectedMonth ? 'selected' : ''}>${monthNames[i]}</option>`;
    }).join('')}
                        </select>
                        <select id="expenseYear" class="search-input" style="width: auto;">
                            ${Array.from({ length: 5 }, (_, i) => {
        const year = new Date().getFullYear() - i;
        return `<option value="${year}" ${year === expensesSelectedYear ? 'selected' : ''}>${year}</option>`;
    }).join('')}
                        </select>
                        <button class="btn btn-primary" id="loadExpenseHistoryBtn">Load</button>
                    </div>
                    <input type="text" id="expenseSearch" placeholder="Search by paid item, note, or category" class="search-input">
                    <select id="expenseCategoryFilter" class="search-input" style="width: auto;">
                        <option value="">All Groups</option>
                    </select>
                    ${isAdminUser() ? '<button class="btn btn-primary" id="addExpenseBtn">Add Expense</button>' : ''}
                </div>
            </div>
            <div id="expensesSummary" style="margin-bottom: 1.5rem;">
                <div class="loading">Loading summary...</div>
            </div>
            <div id="expensesAnalyticsContainer" style="margin-bottom: 1.5rem;"></div>
            <div id="expensesTableContainer">
                <div class="loading">Loading expenses...</div>
            </div>
        </div>
    `;
    const contentBody = document.getElementById('contentBody');
    if (!contentBody) return;

    contentBody.innerHTML = html;

    const searchInput = document.getElementById('expenseSearch');
    if (searchInput) {
        searchInput.addEventListener('input', Utils.debounce(function () {
            loadExpensesTable();
        }, 300));
    }

    const startDateInput = document.getElementById('expenseStartDate');
    const endDateInput = document.getElementById('expenseEndDate');
    if (startDateInput) startDateInput.addEventListener('change', loadExpensesTable);
    if (endDateInput) endDateInput.addEventListener('change', loadExpensesTable);

    const categoryFilter = document.getElementById('expenseCategoryFilter');
    if (categoryFilter) categoryFilter.addEventListener('change', loadExpensesTable);

    const addBtn = document.getElementById('addExpenseBtn');
    if (addBtn) addBtn.addEventListener('click', showAddExpenseForm);

    // History view controls
    const expenseViewCurrentBtn = document.getElementById('expenseViewCurrentBtn');
    const expenseViewHistoryBtn = document.getElementById('expenseViewHistoryBtn');
    const expenseHistorySelector = document.getElementById('expenseHistorySelector');
    const loadExpenseHistoryBtn = document.getElementById('loadExpenseHistoryBtn');
    const expenseMonth = document.getElementById('expenseMonth');
    const expenseYear = document.getElementById('expenseYear');

    if (expenseViewCurrentBtn) {
        expenseViewCurrentBtn.addEventListener('click', function () {
            expensesViewMode = 'current';
            expenseViewCurrentBtn.classList.remove('btn-secondary');
            expenseViewCurrentBtn.classList.add('btn-primary');
            expenseViewHistoryBtn.classList.remove('btn-primary');
            expenseViewHistoryBtn.classList.add('btn-secondary');
            expenseHistorySelector.style.display = 'none';
            // Clear date filters when switching to current month
            const startDateInput = document.getElementById('expenseStartDate');
            const endDateInput = document.getElementById('expenseEndDate');
            if (startDateInput) startDateInput.value = '';
            if (endDateInput) endDateInput.value = '';
            loadExpensesTable();
            loadExpensesSummary('', '');
        });
    }

    if (expenseViewHistoryBtn) {
        expenseViewHistoryBtn.addEventListener('click', function () {
            expensesViewMode = 'history';
            expenseViewHistoryBtn.classList.remove('btn-secondary');
            expenseViewHistoryBtn.classList.add('btn-primary');
            expenseViewCurrentBtn.classList.remove('btn-primary');
            expenseViewCurrentBtn.classList.add('btn-secondary');
            expenseHistorySelector.style.display = 'flex';
        });
    }

    if (loadExpenseHistoryBtn) {
        loadExpenseHistoryBtn.addEventListener('click', function () {
            expensesSelectedMonth = parseInt(expenseMonth.value);
            expensesSelectedYear = parseInt(expenseYear.value);
            loadExpensesTable();
            // Calculate start and end dates for the selected month
            const startDate = `${expensesSelectedYear}-${String(expensesSelectedMonth).padStart(2, '0')}-01`;
            const lastDay = new Date(expensesSelectedYear, expensesSelectedMonth, 0).getDate();
            const endDate = `${expensesSelectedYear}-${String(expensesSelectedMonth).padStart(2, '0')}-${String(lastDay).padStart(2, '0')}`;
            loadExpensesSummary(startDate, endDate);
        });
    }

    loadExpensesAnalytics();
    // Load expenses table and categories (non-blocking)
    // Load table first, then summary and categories
    loadExpensesTable();
    // Load summary and categories in parallel (won't block table)
    setTimeout(() => {
        loadExpensesSummary('', '');
        loadExpenseCategories();
    }, 100);

    // Safety fallback: If still loading after 20 seconds, force clear
    setTimeout(() => {
        const tableContainer = document.getElementById('expensesTableContainer');
        const summaryDiv = document.getElementById('expensesSummary');

        if (tableContainer && tableContainer.innerHTML.includes('Loading')) {
            console.warn('Expenses table still loading after 20s, forcing clear');
            tableContainer.innerHTML = '<div class="error">Loading timeout. Please refresh the page.</div>';
        }

        if (summaryDiv && summaryDiv.innerHTML.includes('Loading')) {
            console.warn('Expenses summary still loading after 20s, forcing clear');
            renderExpensesSummary({ total_expenses: 0, categories: [] });
        }
    }, 20000);
}

function loadExpensesAnalytics() {
    const container = document.getElementById('expensesAnalyticsContainer');
    if (!container) return;

    const range = window.analyticsRanges?.expenses || '30d';
    fetch(`api/reports.php?action=expenses&range=${encodeURIComponent(range)}`)
        .then(res => res.json())
        .then(result => {
            if (!result.success) throw new Error(result.message || 'Failed to load expenses analytics');
            const data = result.data || {};
            container.innerHTML = `
                ${renderRangeSelector('expenses', range)}
                <div class="activity-analytics-grid">
                    ${renderAnalyticsBlock('Expense Categories', 'Spending by category', 'expensesPageCategoryChart', data.charts?.categories || [], 'bar', '#b45309')}
                    ${renderAnalyticsBlock('Monthly Expenses', 'Month-by-month spending', 'expensesPageMonthlyChart', data.charts?.monthly_expenses || [], 'line', '#dc2626')}
                </div>
            `;
            renderReportCharts([
                { id: 'expensesPageCategoryChart', type: 'bar', series: data.charts?.categories || [], color: '#b45309' },
                { id: 'expensesPageMonthlyChart', type: 'line', series: data.charts?.monthly_expenses || [], color: '#dc2626' }
            ]);
        })
        .catch(err => {
            container.innerHTML = `<div class="error">${err.message}</div>`;
        });
}

function loadExpenseCategories() {
    // Cancel any existing category request
    if (activeRequests['expenseCategories']) {
        activeRequests['expenseCategories'].abort();
    }

    // Create new abort controller for this request
    const abortController = new AbortController();
    activeRequests['expenseCategories'] = abortController;

    fetch('api/expenses.php?action=stats', {
        signal: abortController.signal
    })
        .then(async res => {
            if (!res.ok) return null;
            const text = await res.text();
            return text ? JSON.parse(text) : null;
        })
        .then(data => {
            // Check if request was cancelled
            if (abortController.signal.aborted) {
                return;
            }

            delete activeRequests['expenseCategories'];

            if (data && data.success && data.data.categories) {
                const categoryFilter = document.getElementById('expenseCategoryFilter');
                if (categoryFilter) {
                    // Clear existing options except "All Categories"
                    categoryFilter.innerHTML = '<option value="">All Categories</option>';
                    data.data.categories.forEach(cat => {
                        const option = document.createElement('option');
                        option.value = cat;
                        option.textContent = cat;
                        categoryFilter.appendChild(option);
                    });
                }
            }
        })
        .catch(err => {
            delete activeRequests['expenseCategories'];

            // Don't log error if request was aborted
            if (err.name !== 'AbortError') {
                console.error('Error loading categories:', err);
            }
        });
}

function loadExpensesTable(page = 1) {
    // Cancel any existing expenses table request
    if (activeRequests['expensesTable']) {
        activeRequests['expensesTable'].abort();
    }

    // Show loading state
    const container = document.getElementById('expensesTableContainer');
    if (container) {
        container.innerHTML = '<div class="loading">Loading expenses...</div>';
    }

    // Create new abort controller for this request
    const abortController = new AbortController();
    activeRequests['expensesTable'] = abortController;

    // Set timeout to prevent hanging
    const timeoutId = setTimeout(() => {
        if (!abortController.signal.aborted) {
            abortController.abort();
            // Always clear loading state on timeout
            const container = document.getElementById('expensesTableContainer');
            if (container) {
                container.innerHTML = '<div class="error">Request timed out. Please try again or refresh the page.</div>';
            }
            delete activeRequests['expensesTable'];
        }
    }, 10000); // 10 second timeout (reduced from 15)

    const search = document.getElementById('expenseSearch')?.value || '';
    let startDate = document.getElementById('expenseStartDate')?.value || '';
    let endDate = document.getElementById('expenseEndDate')?.value || '';
    const category = document.getElementById('expenseCategoryFilter')?.value || '';
    const limit = 20;

    // If in history mode and no custom dates set, use selected month/year
    if (expensesViewMode === 'history' && !startDate && !endDate) {
        startDate = `${expensesSelectedYear}-${String(expensesSelectedMonth).padStart(2, '0')}-01`;
        const lastDay = new Date(expensesSelectedYear, expensesSelectedMonth, 0).getDate();
        endDate = `${expensesSelectedYear}-${String(expensesSelectedMonth).padStart(2, '0')}-${String(lastDay).padStart(2, '0')}`;
    } else if (expensesViewMode === 'current' && !startDate && !endDate) {
        // For current month, set dates to current month
        const now = new Date();
        const currentYear = now.getFullYear();
        const currentMonth = now.getMonth() + 1;
        startDate = `${currentYear}-${String(currentMonth).padStart(2, '0')}-01`;
        const lastDay = new Date(currentYear, currentMonth, 0).getDate();
        endDate = `${currentYear}-${String(currentMonth).padStart(2, '0')}-${String(lastDay).padStart(2, '0')}`;
    }

    let url = `api/expenses.php?action=list&page=${page}&limit=${limit}`;
    if (startDate) url += `&start_date=${startDate}`;
    if (endDate) url += `&end_date=${endDate}`;
    if (category) url += `&category=${encodeURIComponent(category)}`;
    if (search) url += `&expense_type=${encodeURIComponent(search)}`;

    fetch(url, {
        signal: abortController.signal
    })
        .then(async res => {
            clearTimeout(timeoutId);

            // Check if already aborted
            if (abortController.signal.aborted) {
                return null;
            }

            const text = await res.text();
            if (!res.ok) {
                throw new Error(`HTTP ${res.status}: ${text.substring(0, 200)}`);
            }
            if (!text || text.trim() === '') {
                throw new Error('Empty response from server');
            }
            try {
                const data = JSON.parse(text);
                return data;
            } catch (e) {
                console.error('JSON parse error:', e, 'Response:', text.substring(0, 200));
                throw new Error('Invalid JSON response: ' + text.substring(0, 100));
            }
        })
        .then(data => {
            // Check if request was cancelled
            if (abortController.signal.aborted) {
                return;
            }

            // Clear timeout if still active
            clearTimeout(timeoutId);
            delete activeRequests['expensesTable'];

            const container = document.getElementById('expensesTableContainer');
            if (!container) return;

            // Always render something, even if API fails
            if (data && data.success) {
                // Load summary in background (non-blocking)
                loadExpensesSummary(startDate, endDate);
                renderExpensesTable(data.data || [], data.pagination || {});
            } else {
                // Show error but also show empty table interface
                container.innerHTML =
                    '<div class="error" style="margin-bottom: 1rem;">Failed to load expenses: ' + (data?.message || 'Unknown error') + '</div>' +
                    '<div class="info" style="padding: 2rem; text-align: center;">No expenses data available. Try refreshing the page.</div>';
                // Still try to load summary
                loadExpensesSummary(startDate, endDate);
            }
        })
        .catch(err => {
            // Always clear timeout and request tracking
            clearTimeout(timeoutId);
            delete activeRequests['expensesTable'];

            // Don't show error if request was aborted (user navigated away)
            if (err.name === 'AbortError') {
                return;
            }

            console.error('Expenses error:', err);
            const container = document.getElementById('expensesTableContainer');
            if (container) {
                // Always clear loading state and show error
                container.innerHTML =
                    `<div class="error" style="margin-bottom: 1rem;">Error loading expenses: ${err.message}</div>` +
                    '<div class="info" style="padding: 2rem; text-align: center;">Unable to load expenses. Please check your connection and try again.</div>';
            }
            // Still try to load summary (might work even if list fails)
            try {
                loadExpensesSummary(startDate, endDate);
            } catch (e) {
                console.error('Failed to load summary:', e);
            }
        });
}

function loadExpensesSummary(startDate, endDate) {
    // Cancel any existing expenses summary request
    if (activeRequests['expensesSummary']) {
        activeRequests['expensesSummary'].abort();
    }

    // Create new abort controller for this request
    const abortController = new AbortController();
    activeRequests['expensesSummary'] = abortController;

    // Set timeout to prevent hanging
    const timeoutId = setTimeout(() => {
        abortController.abort();
        // Always show summary on timeout (clear loading state)
        const summaryDiv = document.getElementById('expensesSummary');
        if (summaryDiv) {
            renderExpensesSummary({ total_expenses: 0, categories: [] });
        }
    }, 10000); // 10 second timeout for summary

    let url = 'api/expenses.php?action=stats';
    if (startDate) url += `&start_date=${startDate}`;
    if (endDate) url += `&end_date=${endDate}`;

    fetch(url, {
        signal: abortController.signal
    })
        .then(async res => {
            clearTimeout(timeoutId);

            // Check if already aborted
            if (abortController.signal.aborted) {
                return null;
            }

            if (!res.ok) {
                // Show empty summary on error
                renderExpensesSummary({ total_expenses: 0, categories: [] });
                return null;
            }
            const text = await res.text();
            if (!text || text.trim() === '') {
                renderExpensesSummary({ total_expenses: 0, categories: [] });
                return null;
            }
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('Summary JSON parse error:', e, 'Response:', text.substring(0, 200));
                renderExpensesSummary({ total_expenses: 0, categories: [] });
                return null;
            }
        })
        .then(data => {
            // Check if request was cancelled
            if (abortController.signal.aborted) {
                return;
            }

            // Always clear timeout and request tracking
            clearTimeout(timeoutId);
            delete activeRequests['expensesSummary'];

            if (data && data.success) {
                renderExpensesSummary(data.data);
            } else {
                // Show empty summary if data is invalid
                renderExpensesSummary({ total_expenses: 0, categories: [] });
            }
        })
        .catch(err => {
            // Always clear timeout and request tracking
            clearTimeout(timeoutId);
            delete activeRequests['expensesSummary'];

            // Don't log error if request was aborted (user navigated away)
            if (err.name !== 'AbortError') {
                console.error('Error loading summary:', err);
                // Always show empty summary on error (clears loading state)
                renderExpensesSummary({ total_expenses: 0, categories: [] });
            }
        });
}

function renderExpensesSummary(summary) {
    const summaryDiv = document.getElementById('expensesSummary');
    if (!summaryDiv) return;

    // Ensure summary object exists
    summary = summary || { total_expenses: 0, categories: [] };

    const html = `
        <div class="dashboard-stats">
            <div class="stat-card">
                <h3>Total Money Spent</h3>
                <p style="font-size: 2rem; font-weight: bold; color: #e74c3c;">
                    ${Utils.formatCurrency(summary.total_expenses || 0)}
                </p>
            </div>
            <div class="stat-card">
                <h3>Expense Groups</h3>
                <p style="font-size: 1.5rem; font-weight: bold; color: var(--secondary-color);">
                    ${(summary.categories || []).length}
                </p>
            </div>
        </div>
    `;
    summaryDiv.innerHTML = html;
}

function renderExpensesTable(expenses, pagination) {
    const container = document.getElementById('expensesTableContainer');
    if (!container) return;

    // Show month/year info if in history mode
    let monthInfo = '';
    if (expensesViewMode === 'history') {
        const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December'];
        monthInfo = `
            <div style="margin-bottom: 1rem;">
                <h3>Expenses for ${monthNames[expensesSelectedMonth - 1]} ${expensesSelectedYear}</h3>
                <p>Total expense records: ${expenses ? expenses.length : 0}</p>
            </div>
        `;
    }

    if (!expenses || expenses.length === 0) {
        container.innerHTML = monthInfo + '<div class="empty-state"><strong>No expenses found</strong>No expense record matches this filter or month.</div>';
        return;
    }

    const currentPage = pagination ? (parseInt(pagination.page) || 1) : 1;
    const totalPages = pagination ? (parseInt(pagination.total_pages) || 1) : 1;
    const limit = pagination ? (parseInt(pagination.limit) || 20) : 20;
    const startIndex = (currentPage - 1) * limit;

    const html = monthInfo + `
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Paid For</th>
                    <th>Category</th>
                    <th>Description</th>
                    <th>Amount</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                ${expenses.map((e, idx) => `
                    <tr>
                        <td data-label="#">${startIndex + idx + 1}</td>
                        <td data-label="Date">${Utils.formatDate(e.expense_date)}</td>
                        <td data-label="Paid For"><strong>${e.expense_type}</strong></td>
                        <td data-label="Category">${e.category || 'N/A'}</td>
                        <td data-label="Description">${e.description || '-'}</td>
                        <td data-label="Amount"><strong style="color: #e74c3c;">${Utils.formatCurrency(e.amount || 0)}</strong></td>
                        <td data-label="Actions">
                            ${isAdminUser() ? `
                                <button class="btn btn-sm btn-primary" onclick="showEditExpenseForm(${e.id})">Edit</button>
                                <button class="btn btn-sm btn-danger" onclick="deleteExpense(${e.id})">Delete</button>
                            ` : '<span style="color:#6b7280;">Read only</span>'}
                        </td>
                    </tr>
                `).join('')}
            </tbody>
        </table>
        ${pagination && totalPages > 1 ? `
            <div class="pagination" style="margin-top: 1.5rem; display: flex; justify-content: center; align-items: center; gap: 1rem; flex-wrap: wrap;">
                <button class="btn btn-secondary" ${currentPage <= 1 ? 'disabled' : ''} onclick="loadExpensesTable(${currentPage - 1})">
                    Previous
                </button>
                <span>Page</span>
                <input type="number" id="expensesPageInput" min="1" max="${totalPages}" value="${currentPage}" style="width: 60px; padding: 0.25rem; text-align: center; border: 1px solid #ddd; border-radius: 4px;" onchange="const page = parseInt(this.value) || 1; if (page >= 1 && page <= ${totalPages}) loadExpensesTable(page); else this.value = ${currentPage};" onkeypress="if(event.key === 'Enter') { const page = parseInt(this.value) || 1; if (page >= 1 && page <= ${totalPages}) loadExpensesTable(page); else this.value = ${currentPage}; }">
                <span>of ${totalPages}</span>
                <button class="btn btn-secondary" ${currentPage >= totalPages ? 'disabled' : ''} onclick="loadExpensesTable(${currentPage + 1})">
                    Next
                </button>
            </div>
        ` : ''}
    `;
    document.getElementById('expensesTableContainer').innerHTML = html;
}

function showAddExpenseForm() {
    if (!requireAdminAccess('add expenses')) return;
    showExpenseForm();
}

function showEditExpenseForm(expenseId) {
    if (!requireAdminAccess('edit expenses')) return;

    fetch(`api/expenses.php?action=get&id=${expenseId}`)
        .then(async res => {
            if (!res.ok) throw new Error('Failed to load expense');
            const text = await res.text();
            return JSON.parse(text);
        })
        .then(data => {
            if (data.success) {
                showExpenseForm(data.data);
            } else {
                Utils.showNotification('Failed to load expense details', 'error');
            }
        })
        .catch(err => {
            console.error('Error loading expense:', err);
            Utils.showNotification('Error loading expense', 'error');
        });
}

function showExpenseForm(expense = null) {
    const isEdit = expense !== null;
    const html = `
        <div class="modal" id="expenseModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>${isEdit ? 'Edit Expense' : 'Add Expense'}</h2>
                    <button class="modal-close" onclick="closeExpenseModal()">&times;</button>
                </div>
                <form id="expenseForm" class="modal-body">
                    ${isEdit ? `<input type="hidden" id="expenseId" value="${expense.id}">` : ''}
                    <div class="simple-note"><strong>Tip:</strong> Write what the gym paid for, the amount, and the date. Keep the note short and clear.</div>
                    <div class="form-group">
                        <label>What was paid for? *</label>
                        <input type="text" id="expenseType" name="expense_type" value="${expense?.expense_type || ''}" required placeholder="Example: Rent, Electricity, Cleaning">
                    </div>
                    <div class="form-group">
                        <label>Group</label>
                        <select id="expenseCategory" name="category" style="width: 100%; margin-bottom: 0.5rem;">
                            <option value="">Choose existing group (optional)</option>
                        </select>
                        <input type="text" id="expenseCategoryNew" name="category_new" value="${expense?.category || ''}" placeholder="Or type a new group name" style="margin-top: 0.25rem;">
                        <small>You can choose an existing group or type a new one.</small>
                    </div>
                    <div class="form-group">
                        <label>Amount *</label>
                        <input type="number" step="0.01" id="expenseAmount" name="amount" value="${expense?.amount || ''}" min="0" required>
                    </div>
                    <div class="form-group">
                        <label>Date *</label>
                        <input type="date" id="expenseDate" name="expense_date" value="${expense?.expense_date || new Date().toISOString().split('T')[0]}" required>
                    </div>
                    <div class="form-group">
                        <label>Short Note</label>
                        <textarea id="expenseDescription" name="description" rows="3" placeholder="Optional short description">${expense?.description || ''}</textarea>
                    </div>
                    <div class="form-group">
                        <label>Extra Notes</label>
                        <textarea id="expenseNotes" name="notes" rows="2" placeholder="Optional extra notes">${expense?.notes || ''}</textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeExpenseModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Expense</button>
                    </div>
                </form>
            </div>
        </div>
    `;
    document.body.insertAdjacentHTML('beforeend', html);

    // Load categories into dropdown
    loadExpenseCategoriesForForm();

    // Set existing category if editing
    if (expense && expense.category) {
        const categorySelect = document.getElementById('expenseCategory');
        const categoryNew = document.getElementById('expenseCategoryNew');
        // Check if category exists in dropdown, if not show new input
        const optionExists = Array.from(categorySelect.options).some(opt => opt.value === expense.category);
        if (optionExists) {
            categorySelect.value = expense.category;
        } else {
            categoryNew.value = expense.category;
            categoryNew.style.display = 'block';
        }
    }

    // No need to hide/show the new category field anymore; both inputs are always available.

    const form = document.getElementById('expenseForm');
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        saveExpense();
    });
}

function loadExpenseCategoriesForForm() {
    fetch('api/expenses.php?action=stats')
        .then(async res => {
            if (!res.ok) return null;
            const text = await res.text();
            return text ? JSON.parse(text) : null;
        })
        .then(data => {
            const categorySelect = document.getElementById('expenseCategory');
            if (categorySelect && data && data.success && data.data.categories) {
                // Add existing categories to dropdown
                data.data.categories.forEach(cat => {
                    const option = document.createElement('option');
                    option.value = cat;
                    option.textContent = cat;
                    categorySelect.appendChild(option);
                });
            }
        })
        .catch(err => {
            console.error('Error loading categories for form:', err);
        });
}

function closeExpenseModal() {
    const modal = document.getElementById('expenseModal');
    if (modal) modal.remove();
}

function saveExpense() {
    const expenseId = document.getElementById('expenseId')?.value;
    const isEdit = !!expenseId;
    const categorySelect = document.getElementById('expenseCategory');
    const categoryNew = document.getElementById('expenseCategoryNew');

    // Get category: prefer newly typed category if provided, otherwise use dropdown
    let category = '';
    if (categoryNew && categoryNew.value.trim() !== '') {
        category = categoryNew.value.trim();
    } else if (categorySelect) {
        category = categorySelect.value || '';
    }

    const expenseData = {
        expense_type: document.getElementById('expenseType').value,
        category: category || null,
        amount: parseFloat(document.getElementById('expenseAmount').value),
        expense_date: document.getElementById('expenseDate').value,
        description: document.getElementById('expenseDescription').value || null,
        notes: document.getElementById('expenseNotes').value || null
    };
    if (isEdit) expenseData.id = expenseId;

    fetch(`api/expenses.php?action=${isEdit ? 'update' : 'create'}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(expenseData)
    })
        .then(async res => {
            if (!res.ok) throw new Error('Failed to save expense');
            const text = await res.text();
            return JSON.parse(text);
        })
        .then(data => {
            if (data.success) {
                Utils.showNotification(data.message || (isEdit ? 'Expense updated successfully.' : 'Expense added successfully.'), 'success');
                closeExpenseModal();
                loadExpensesTable();
                if (currentSection === 'dashboard') loadDashboard();
            } else {
                Utils.showNotification(data.message || 'Failed to save expense', 'error');
            }
        })
        .catch(err => {
            console.error('Expense save error:', err);
            Utils.showNotification(err.message || 'Error saving expense', 'error');
        });
}

function deleteExpense(expenseId) {
    if (!requireAdminAccess('delete expenses')) return;
    if (!confirm('Are you sure you want to delete this expense? This action cannot be undone.')) return;

    fetch(`api/expenses.php?action=delete&id=${expenseId}`, { method: 'POST' })
        .then(async res => {
            if (!res.ok) throw new Error('Failed to delete expense');
            const text = await res.text();
            return JSON.parse(text);
        })
        .then(data => {
            if (data && data.success) {
                Utils.showNotification('Expense deleted successfully.', 'success');
                loadExpensesTable();
                if (currentSection === 'dashboard') loadDashboard();
            } else {
                Utils.showNotification(data?.message || 'Failed to delete expense', 'error');
            }
        })
        .catch(err => {
            console.error('Expense delete error:', err);
            Utils.showNotification(err.message || 'Error deleting expense', 'error');
        });
}

function loadReports() {
    const html = `
        <div class="reports-section">
            ${renderSectionGuideCard({
                chip: 'Reports Help',
                title: 'Choose the question you want answered',
                description: 'Reports are easier to use when you think in simple questions: how many members, who came today, how much money came in, and who has not paid.',
                steps: [
                    'Use Members Overview for total active members.',
                    'Use Attendance Overview for today and this month.',
                    'Use Payment Overview for revenue numbers.',
                    'Use Unpaid Members for late payers.'
                ]
            })}
            <h2>Reports</h2>
            <div class="reports-grid">
                <div class="report-card" data-report="members" onclick="generateReport('members', this)">
                    <h3>📊 Members Overview</h3>
                    <p>See total, active, and overdue members</p>
                </div>
                <div class="report-card" data-report="attendance" onclick="generateReport('attendance', this)">
                    <h3>✓ Attendance Overview</h3>
                    <p>See who came today and this month</p>
                </div>
                <div class="report-card" data-report="payments" onclick="generateReport('payments', this)">
                    <h3>💰 Payment Overview</h3>
                    <p>See revenue and payment totals</p>
                </div>
                <div class="report-card" data-report="defaulters" onclick="generateReport('defaulters', this)">
                    <h3>⚠️ Unpaid Members</h3>
                    <p>See members with overdue or unpaid fees</p>
                </div>
                <div class="report-card" data-report="expenses" onclick="generateReport('expenses', this)">
                    <h3>💸 Expense Overview</h3>
                    <p>See category and monthly expense analytics</p>
                </div>
                <div class="report-card" data-report="profit" onclick="generateReport('profit', this)">
                    <h3>📉 Profit Comparison</h3>
                    <p>Compare revenue, expenses, and profit trend</p>
                </div>
            </div>
            <h2 style="margin-top:2rem;">⬇️ Export Data</h2>
            <div class="reports-grid">
                <div class="report-card" onclick="exportCSV('members','all')">
                    <h3>👥 All Members (CSV)</h3>
                    <p>Download men + women members as a spreadsheet</p>
                </div>
                <div class="report-card" onclick="exportCSV('members','men')">
                    <h3>👨 Men Members (CSV)</h3>
                    <p>Download men-only members list</p>
                </div>
                <div class="report-card" onclick="exportCSV('members','women')">
                    <h3>👩 Women Members (CSV)</h3>
                    <p>Download women-only members list</p>
                </div>
                <div class="report-card" onclick="exportCSV('payments','all')">
                    <h3>💳 All Payments (CSV)</h3>
                    <p>Download full payment history as a spreadsheet</p>
                </div>
                <div class="report-card" onclick="exportCSV('payments','men')">
                    <h3>💳 Men Payments (CSV)</h3>
                    <p>Download men-only payment history</p>
                </div>
                <div class="report-card" onclick="exportCSV('payments','women')">
                    <h3>💳 Women Payments (CSV)</h3>
                    <p>Download women-only payment history</p>
                </div>
            </div>
            <div id="reportResults" style="margin-top: 2rem;"></div>
        </div>
    `;
    document.getElementById('contentBody').innerHTML = html;
}

function generateReport(type, cardEl = null) {
    const resultsDiv = document.getElementById('reportResults');
    resultsDiv.innerHTML = '<div class="loading">Generating report...</div>';

    document.querySelectorAll('.report-card').forEach(card => card.classList.remove('active-report'));
    if (cardEl) cardEl.classList.add('active-report');
    else document.querySelector(`.report-card[data-report="${type}"]`)?.classList.add('active-report');

    const range = window.analyticsRanges?.reports || '30d';
    fetch(`api/reports.php?action=${type}&range=${encodeURIComponent(range)}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                renderReport(data.data, type);
            } else {
                resultsDiv.innerHTML = `<div class="error">${data.message || 'Failed to generate report'}</div>`;
            }
        })
        .catch(err => {
            console.error('Report error:', err);
            resultsDiv.innerHTML = '<div class="error">Error generating report</div>';
        });
}

function exportCSV(type, gender) {
    const url = `api/reports.php?action=export&type=${encodeURIComponent(type)}&gender=${encodeURIComponent(gender)}`;
    const a = document.createElement('a');
    a.href = url;
    a.download = '';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

function renderReport(data, type) {
    const resultsDiv = document.getElementById('reportResults');

    switch (type) {
        case 'members':
            resultsDiv.innerHTML = `
                <div class="report-content">
                    <h3>Members Overview</h3>
                    <div class="stats-grid">
                        <div class="stat-item"><strong>Total Men Members:</strong> ${data.men?.total || 0}</div>
                        <div class="stat-item"><strong>Active Men:</strong> ${data.men?.active || 0}</div>
                        <div class="stat-item"><strong>Total Women Members:</strong> ${data.women?.total || 0}</div>
                        <div class="stat-item"><strong>Active Women:</strong> ${data.women?.active || 0}</div>
                        <div class="stat-item"><strong>Checked In Now:</strong> ${data.operations?.checked_in_now || 0}</div>
                        <div class="stat-item"><strong>Overdue Members:</strong> ${data.operations?.overdue || 0}</div>
                        <div class="stat-item"><strong>Due Today:</strong> ${data.operations?.due_today || 0}</div>
                        <div class="stat-item"><strong>New This Month:</strong> ${data.operations?.new_this_month || 0}</div>
                        <div class="stat-item"><strong>Total Members:</strong> ${(data.men?.total || 0) + (data.women?.total || 0)}</div>
                        <div class="stat-item"><strong>Total Active:</strong> ${(data.men?.active || 0) + (data.women?.active || 0)}</div>
                        <div class="stat-item"><strong>Outstanding Active Due:</strong> ${Utils.formatCurrency(data.operations?.active_due_amount || 0)}</div>
                    </div>
                    <div class="activity-analytics-grid" style="margin-top:1rem;">
                        <div class="chart-card"><div class="chart-card-header"><h3>Monthly Member Growth</h3><small>Growth trend</small></div><canvas id="membersGrowthChart" width="520" height="220"></canvas></div>
                        <div class="chart-card"><div class="chart-card-header"><h3>Gender Split</h3><small>Men vs women</small></div><canvas id="membersGenderChart" width="520" height="220"></canvas></div>
                        <div class="chart-card"><div class="chart-card-header"><h3>Active / Inactive Split</h3><small>Status overview</small></div><canvas id="membersStatusChart" width="520" height="220"></canvas></div>
                    </div>
                </div>
            `;
            renderReportCharts([
                { id: 'membersGrowthChart', type: 'line', series: data.charts?.monthly_growth || [], color: '#166534' },
                { id: 'membersGenderChart', type: 'bar', series: data.charts?.gender_split || [], color: '#0369a1' },
                { id: 'membersStatusChart', type: 'bar', series: data.charts?.active_split || [], color: '#b45309' }
            ]);
            break;
        case 'defaulters':
            const defaulters = data.defaulters || [];
            resultsDiv.innerHTML = `
                <div class="report-content">
                    <h3>Unpaid Members (${defaulters.length})</h3>
                    <div class="stats-grid" style="margin-bottom: 1rem;">
                        <div class="stat-item"><strong>Total Unpaid Members:</strong> ${data.total_count || defaulters.length}</div>
                        <div class="stat-item"><strong>Overdue Members:</strong> ${data.overdue_count || 0}</div>
                        <div class="stat-item"><strong>Members With Outstanding Dues:</strong> ${data.outstanding_dues_count || 0}</div>
                        <div class="stat-item"><strong>Total Outstanding:</strong> ${Utils.formatCurrency(data.total_outstanding_amount || 0)}</div>
                    </div>
                    <div class="activity-analytics-grid" style="margin-bottom:1rem;">
                        <div class="chart-card"><div class="chart-card-header"><h3>Gender Split</h3><small>Men vs women with dues</small></div><canvas id="defaultersGenderChart" width="520" height="220"></canvas></div>
                        <div class="chart-card"><div class="chart-card-header"><h3>Overdue Bands</h3><small>By overdue days</small></div><canvas id="defaultersBandsChart" width="520" height="220"></canvas></div>
                        <div class="chart-card"><div class="chart-card-header"><h3>Top Defaulters</h3><small>Highest due amounts</small></div><canvas id="defaultersTopChart" width="520" height="220"></canvas></div>
                        <div class="chart-card"><div class="chart-card-header"><h3>Dues Trend</h3><small>Outstanding dues over time</small></div><canvas id="defaultersTrendChart" width="520" height="220"></canvas></div>
                    </div>
                    ${defaulters.length > 0 ? `
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Member Code</th>
                                    <th>Name</th>
                                    <th>Gender</th>
                                    <th>Phone</th>
                                    <th>Next Fee Due</th>
                                    <th>Days Overdue</th>
                                    <th>Due Amount</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${defaulters.map((d, idx) => `
                                        <tr>
                                            <td>${idx + 1}</td>
                                            <td>${d.member_code}</td>
                                            <td>${d.name}</td>
                                            <td>${d.gender === 'women' ? '👩 Women' : '👨 Men'}</td>
                                            <td>${d.phone || '-'}</td>
                                            <td>${d.next_fee_due_date ? Utils.formatDate(d.next_fee_due_date) : 'N/A'}</td>
                                            <td><span style="color: ${d.days_overdue > 0 ? 'red' : '#f39c12'}; font-weight: bold;">${d.days_overdue || 0} days</span></td>
                                            <td><strong style="color: #e74c3c;">${Utils.formatCurrency(d.total_due_amount || 0)}</strong></td>
                                            <td>
                                                ${isAdminUser() ? `<button class="btn btn-sm btn-primary" onclick="currentGender='${d.gender}'; updateFee(${d.id}, '${d.member_code}')">Take Fee</button>` : '<span style="color:#6b7280;">Read only</span>'}
                                            </td>
                                        </tr>
                                    `).join('')}
                            </tbody>
                        </table>
                    ` : '<div class="empty-state"><strong>No unpaid members found</strong>All members are up to date.</div>'}
                </div>
            `;
            renderReportCharts([
                { id: 'defaultersGenderChart', type: 'bar', series: data.charts?.gender_split || [], color: '#0369a1' },
                { id: 'defaultersBandsChart', type: 'bar', series: data.charts?.overdue_bands || [], color: '#b45309' },
                { id: 'defaultersTopChart', type: 'bar', series: data.charts?.top_defaulters || [], color: '#dc2626' },
                { id: 'defaultersTrendChart', type: 'line', series: data.charts?.dues_trend || [], color: '#7c3aed' }
            ]);
            break;
        case 'payments':
            resultsDiv.innerHTML = `
                <div class="report-content">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
                        <h3>Payment Overview</h3>
                        ${renderRangeSelector('reports', window.analyticsRanges?.reports || '30d')}
                    </div>
                    <div class="stats-grid">
                        <div class="stat-item"><strong>Total Payments:</strong> ${data.total_payments || 0}</div>
                        <div class="stat-item"><strong>Total Revenue:</strong> ${Utils.formatCurrency(data.total_revenue || 0)}</div>
                        <div class="stat-item"><strong>Average Payment:</strong> ${Utils.formatCurrency(data.avg_payment || 0)}</div>
                        <div class="stat-item"><strong>Payments Today:</strong> ${data.payments_today || 0}</div>
                        <div class="stat-item"><strong>Revenue Today:</strong> ${Utils.formatCurrency(data.revenue_today || 0)}</div>
                        <div class="stat-item"><strong>Payments This Month:</strong> ${data.payments_this_month || 0}</div>
                        <div class="stat-item"><strong>Revenue This Month:</strong> ${Utils.formatCurrency(data.revenue_this_month || 0)}</div>
                        <div class="stat-item"><strong>Pending Remaining Amount:</strong> ${Utils.formatCurrency(data.pending_remaining_amount || 0)}</div>
                    </div>
                    <div class="activity-analytics-grid" style="margin-top:1rem;">
                        <div class="chart-card"><div class="chart-card-header"><h3>Daily Revenue</h3><small>Last 30 days</small></div><canvas id="paymentsDailyChart" width="520" height="220"></canvas></div>
                        <div class="chart-card"><div class="chart-card-header"><h3>Monthly Revenue</h3><small>Month-by-month</small></div><canvas id="paymentsMonthlyChart" width="520" height="220"></canvas></div>
                        <div class="chart-card"><div class="chart-card-header"><h3>Payment Methods</h3><small>Method usage</small></div><canvas id="paymentsMethodChart" width="520" height="220"></canvas></div>
                    </div>
                </div>
            `;
            renderReportCharts([
                { id: 'paymentsDailyChart', type: 'line', series: data.charts?.daily_revenue || [], color: '#166534' },
                { id: 'paymentsMonthlyChart', type: 'line', series: data.charts?.monthly_revenue || [], color: '#0369a1' },
                { id: 'paymentsMethodChart', type: 'bar', series: data.charts?.payment_methods || [], color: '#7c3aed' }
            ]);
            break;
        case 'attendance':
            resultsDiv.innerHTML = `
                <div class="report-content">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
                        <h3>Attendance Statistics</h3>
                        ${renderRangeSelector('reports', window.analyticsRanges?.reports || '30d')}
                    </div>
                    <div class="stats-grid">
                        <div class="stat-item"><strong>Today's Attendance:</strong> ${data.today || 0}</div>
                        <div class="stat-item"><strong>Today's Unique Members:</strong> ${data.today_unique_members || 0}</div>
                        <div class="stat-item"><strong>Active Sessions Now:</strong> ${data.active_sessions || 0}</div>
                        <div class="stat-item"><strong>This Month's Attendance:</strong> ${data.this_month || 0}</div>
                        <div class="stat-item"><strong>Unique Members This Month:</strong> ${data.unique_members_this_month || 0}</div>
                    </div>
                    <div class="activity-analytics-grid" style="margin-top:1rem;">
                        <div class="chart-card"><div class="chart-card-header"><h3>Daily Attendance</h3><small>Last 30 days</small></div><canvas id="attendanceDailyChart" width="520" height="220"></canvas></div>
                        <div class="chart-card"><div class="chart-card-header"><h3>Gender Attendance</h3><small>Men vs women</small></div><canvas id="attendanceGenderChart" width="520" height="220"></canvas></div>
                    </div>
                </div>
            `;
            renderReportCharts([
                { id: 'attendanceDailyChart', type: 'line', series: data.charts?.daily_attendance || [], color: '#166534' },
                { id: 'attendanceGenderChart', type: 'bar', series: data.charts?.gender_attendance || [], color: '#0369a1' }
            ]);
            break;
        case 'expenses':
            resultsDiv.innerHTML = `
                <div class="report-content">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
                        <h3>Expense Overview</h3>
                        ${renderRangeSelector('reports', window.analyticsRanges?.reports || '30d')}
                    </div>
                    <div class="stats-grid">
                        <div class="stat-item"><strong>Total Expense Entries:</strong> ${data.total_expenses || 0}</div>
                        <div class="stat-item"><strong>Total Expense Amount:</strong> ${Utils.formatCurrency(data.total_amount || 0)}</div>
                        <div class="stat-item"><strong>Average Expense:</strong> ${Utils.formatCurrency(data.avg_amount || 0)}</div>
                    </div>
                    <div class="activity-analytics-grid" style="margin-top:1rem;">
                        <div class="chart-card"><div class="chart-card-header"><h3>Expense Categories</h3><small>Where money goes</small></div><canvas id="expensesCategoryChart" width="520" height="220"></canvas></div>
                        <div class="chart-card"><div class="chart-card-header"><h3>Monthly Expenses</h3><small>Month-by-month</small></div><canvas id="expensesMonthlyChart" width="520" height="220"></canvas></div>
                    </div>
                </div>
            `;
            renderReportCharts([
                { id: 'expensesCategoryChart', type: 'bar', series: data.charts?.categories || [], color: '#b45309' },
                { id: 'expensesMonthlyChart', type: 'line', series: data.charts?.monthly_expenses || [], color: '#dc2626' }
            ]);
            break;
        case 'profit':
            resultsDiv.innerHTML = `
                <div class="report-content">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
                        <h3>Profit Comparison</h3>
                        ${renderRangeSelector('reports', window.analyticsRanges?.reports || '30d')}
                    </div>
                    <div class="activity-analytics-grid" style="margin-top:1rem;">
                        <div class="chart-card"><div class="chart-card-header"><h3>Revenue / Expenses / Profit</h3><small>Combined multi-line comparison</small></div>${renderChartLegend([{ label: 'Revenue', color: '#166534' }, { label: 'Expenses', color: '#dc2626' }, { label: 'Profit', color: '#0369a1' }], 'profitTrendChart')}<canvas id="profitTrendChart" width="520" height="220"></canvas></div>
                    </div>
                    <div class="data-table-wrapper">
                        <table class="data-table">
                            <thead><tr><th>Period</th><th>Revenue</th><th>Expenses</th><th>Profit</th></tr></thead>
                            <tbody>
                                ${(data.trend || []).map(item => `
                                    <tr>
                                        <td>${item.label}</td>
                                        <td>${Utils.formatCurrency(item.revenue || 0)}</td>
                                        <td>${Utils.formatCurrency(item.expenses || 0)}</td>
                                        <td>${Utils.formatCurrency(item.profit || 0)}</td>
                                    </tr>
                                `).join('') || '<tr><td colspan="4">No profit data available</td></tr>'}
                            </tbody>
                        </table>
                    </div>
                </div>
            `;
            renderReportCharts([
                {
                    id: 'profitTrendChart',
                    type: 'multi-line',
                    datasets: [
                        { label: 'Revenue', color: '#166534', series: (data.trend || []).map(item => ({ label: item.label, total: item.revenue })) },
                        { label: 'Expenses', color: '#dc2626', series: (data.trend || []).map(item => ({ label: item.label, total: item.expenses })) },
                        { label: 'Profit', color: '#0369a1', series: (data.trend || []).map(item => ({ label: item.label, total: item.profit })) }
                    ]
                }
            ]);
            break;
        default:
            resultsDiv.innerHTML = `
                <div class="report-content">
                    <h3>${type.charAt(0).toUpperCase() + type.slice(1)} Report</h3>
                    <pre>${JSON.stringify(data, null, 2)}</pre>
                </div>
            `;
    }
}

function loadImport() {
    const html = `
        <div class="import-section">
            ${renderSectionGuideCard({
                chip: 'Import Help',
                title: 'Import or download data carefully',
                description: 'Use import only when you already have member data in Excel or CSV. For day-to-day use, adding members one by one is safer.',
                steps: [
                    'Pick the correct gender before importing.',
                    'Use download if you want a backup or a report file.',
                    'For large files, wait until the import result appears.'
                ]
            })}
            <div class="import-export-container">
                <!-- Import Section -->
                <div class="import-card">
                    <h2>📥 Import Members from Excel</h2>
            <form id="importForm" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Select Gender *</label>
                    <select id="importGender" name="gender" required>
                        <option value="men">Men</option>
                        <option value="women">Women</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Excel File (.xls, .xlsx, .csv) *</label>
                    <input type="file" id="importFile" name="file" accept=".xls,.xlsx,.csv" required>
                </div>
                        <button type="submit" class="btn btn-primary">Import Members</button>
            </form>
            <div id="importResults"></div>
                </div>

                <!-- Export/Download Section -->
                <div class="export-card">
                    <h2>📤 Download Data</h2>

                    <!-- Download Members -->
                    <div class="download-section">
                        <h3>Download Members Data</h3>
                        <div class="form-group">
                            <label>Select Gender</label>
                            <select id="exportGender" class="form-control">
                                <option value="all">All (Men + Women)</option>
                                <option value="men">Men Only</option>
                                <option value="women">Women Only</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>File Format</label>
                            <select id="exportFormat" class="form-control">
                                <option value="excel">Excel (.xlsx)</option>
                                <option value="csv">CSV (.csv)</option>
                            </select>
                        </div>
                        <button class="btn btn-success" onclick="downloadMembers()">
                            📥 Download Members
                        </button>
                    </div>

                    <!-- Download Expenses -->
                    <div class="download-section">
                        <h3>Download Expenses Data</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label>From Date</label>
                                <input type="date" id="expenseStartDate" class="form-control">
                            </div>
                            <div class="form-group">
                                <label>To Date</label>
                                <input type="date" id="expenseEndDate" class="form-control">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>File Format</label>
                            <select id="expenseFormat" class="form-control">
                                <option value="excel">Excel (.xlsx)</option>
                                <option value="csv">CSV (.csv)</option>
                            </select>
                        </div>
                        <button class="btn btn-success" onclick="downloadExpenses()">
                            📥 Download Expenses
                        </button>
                    </div>

                    <!-- Download Payments -->
                    <div class="download-section">
                        <h3>Download Payments Data</h3>
                        <div class="form-group">
                            <label>Select Gender</label>
                            <select id="paymentExportGender" class="form-control">
                                <option value="all">All (Men + Women)</option>
                                <option value="men">Men Only</option>
                                <option value="women">Women Only</option>
                            </select>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>From Date</label>
                                <input type="date" id="paymentStartDate" class="form-control">
                            </div>
                            <div class="form-group">
                                <label>To Date</label>
                                <input type="date" id="paymentEndDate" class="form-control">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>File Format</label>
                            <select id="paymentFormat" class="form-control">
                                <option value="excel">Excel (.xlsx)</option>
                                <option value="csv">CSV (.csv)</option>
                            </select>
                        </div>
                        <button class="btn btn-success" onclick="downloadPayments()">
                            📥 Download Payments
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
    document.getElementById('contentBody').innerHTML = html;

    // Set default dates (current month)
    const today = new Date();
    const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
    const lastDay = new Date(today.getFullYear(), today.getMonth() + 1, 0);

    document.getElementById('expenseStartDate').value = firstDay.toISOString().split('T')[0];
    document.getElementById('expenseEndDate').value = lastDay.toISOString().split('T')[0];
    document.getElementById('paymentStartDate').value = firstDay.toISOString().split('T')[0];
    document.getElementById('paymentEndDate').value = lastDay.toISOString().split('T')[0];

    const form = document.getElementById('importForm');
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        handleImport();
    });
}

let isImporting = false;

function handleImport() {
    if (isImporting) {
        Utils.showNotification('Import already in progress. Please wait...', 'warning');
        return;
    }

    const form = document.getElementById('importForm');
    const formData = new FormData(form);
    const submitButton = form.querySelector('button[type="submit"]');
    const resultsDiv = document.getElementById('importResults');

    // Disable form and show loading
    isImporting = true;
    submitButton.disabled = true;
    submitButton.textContent = 'Importing... Please wait';
    resultsDiv.innerHTML = '<div class="loading">Processing import... This may take a few minutes for large files.</div>';

    fetch('api/import.php', {
        method: 'POST',
        body: formData
    })
        .then(res => res.json())
        .then(data => {
            isImporting = false;
            submitButton.disabled = false;
            submitButton.textContent = 'Import Members';

            if (data.success) {
                Utils.showNotification(data.message, 'success');
                resultsDiv.innerHTML = `
                <div class="import-results">
                    <h3>Import Results</h3>
                    <p><strong>Successfully imported: ${data.results.success}</strong></p>
                    <p>Failed: ${data.results.failed}</p>
                    ${data.results.errors.length > 0 ? `
                        <div class="errors">
                            <h4>Errors:</h4>
                            <ul>${data.results.errors.map(e => `<li>${e}</li>`).join('')}</ul>
                        </div>
                    ` : ''}
                    ${data.results.duplicates.length > 0 ? `
                        <div class="duplicates">
                            <h4>Duplicates:</h4>
                            <ul>${data.results.duplicates.map(d => `<li>${d}</li>`).join('')}</ul>
                        </div>
                    ` : ''}
                </div>
            `;
            } else {
                Utils.showNotification(data.message, 'error');
                resultsDiv.innerHTML = `<div class="error">${data.message}</div>`;
            }
        })
        .catch(err => {
            isImporting = false;
            submitButton.disabled = false;
            submitButton.textContent = 'Import Members';
            console.error('Import error:', err);
            Utils.showNotification('Error during import: ' + err.message, 'error');
            resultsDiv.innerHTML = `<div class="error">Import failed: ${err.message}</div>`;
        });
}

function downloadMembers() {
    const gender = document.getElementById('exportGender').value;
    const format = document.getElementById('exportFormat').value;

    Utils.showNotification('Preparing download...', 'info');

    window.location.href = `api/download.php?type=members&gender=${gender}&format=${format}`;
}

function downloadExpenses() {
    const startDate = document.getElementById('expenseStartDate').value;
    const endDate = document.getElementById('expenseEndDate').value;
    const format = document.getElementById('expenseFormat').value;

    if (!startDate || !endDate) {
        Utils.showNotification('Please select both start and end dates', 'error');
        return;
    }

    Utils.showNotification('Preparing download...', 'info');

    window.location.href = `api/download.php?type=expenses&start_date=${startDate}&end_date=${endDate}&format=${format}`;
}

function downloadPayments() {
    const gender = document.getElementById('paymentExportGender').value;
    const startDate = document.getElementById('paymentStartDate').value;
    const endDate = document.getElementById('paymentEndDate').value;
    const format = document.getElementById('paymentFormat').value;

    if (!startDate || !endDate) {
        Utils.showNotification('Please select both start and end dates', 'error');
        return;
    }

    Utils.showNotification('Preparing download...', 'info');

    window.location.href = `api/download.php?type=payments&gender=${gender}&start_date=${startDate}&end_date=${endDate}&format=${format}`;
}

let offlineOutboxRefreshBound = false;

function formatOfflineOutboxAge(createdAt) {
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

function formatOutboxReviewValue(value, type = 'text') {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (type === 'money') {
        const amount = Number(value);
        if (Number.isFinite(amount)) {
            return escapeSyncHtml(typeof Utils !== 'undefined' && Utils.formatCurrency ? Utils.formatCurrency(amount) : amount.toFixed(2));
        }
    }

    return escapeSyncHtml(value);
}

function normalizeOutboxReviewValue(value, type = 'text') {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (type === 'money') {
        const amount = Number(value);
        return Number.isFinite(amount) ? amount.toFixed(2) : String(value).trim();
    }

    return String(value).trim();
}

function getOutboxConflictChangedFields(rows) {
    return rows
        .filter(row => row && row.compare !== false)
        .filter(row => normalizeOutboxReviewValue(row.queued, row.type) !== normalizeOutboxReviewValue(row.current, row.type))
        .map(row => row.label);
}

function renderOutboxReviewTable(title, sourceLabel, rows, note = '') {
    const changedFields = getOutboxConflictChangedFields(rows);
    const changedLabel = changedFields.length
        ? `${changedFields.length} changed field${changedFields.length === 1 ? '' : 's'}`
        : 'No field changes detected';
    const rowHtml = rows.map(row => {
        const queuedValue = formatOutboxReviewValue(row.queued, row.type);
        const currentValue = formatOutboxReviewValue(row.current, row.type);
        const differs = row.compare !== false && queuedValue !== currentValue;
        const queuedStyle = differs ? 'color:#92400e;font-weight:700;' : 'color:var(--text-color);';
        const currentStyle = differs ? 'color:#7f1d1d;font-weight:700;' : 'color:var(--text-color);';

        return `
            <tr>
                <td style="padding:0.5rem 0.6rem;border-top:1px solid rgba(148,163,184,0.15);font-weight:700;color:#334155;vertical-align:top;">${escapeSyncHtml(row.label)}</td>
                <td style="padding:0.5rem 0.6rem;border-top:1px solid rgba(148,163,184,0.15);${queuedStyle}vertical-align:top;">${queuedValue}</td>
                <td style="padding:0.5rem 0.6rem;border-top:1px solid rgba(148,163,184,0.15);${currentStyle}vertical-align:top;">${currentValue}</td>
            </tr>
        `;
    }).join('');

    return `
        <div style="margin-top:0.75rem;padding:0.9rem;border-radius:10px;background:var(--bg-secondary);border:1px solid #cbd5e1;">
            <div style="display:flex;justify-content:space-between;gap:0.75rem;flex-wrap:wrap;align-items:center;">
                <div>
                    <strong style="color:#0f172a;">${escapeSyncHtml(title)}</strong>
                    <div style="margin-top:0.2rem;color:var(--text-secondary);font-size:0.9rem;">${escapeSyncHtml(sourceLabel)}</div>
                </div>
                <span style="padding:0.28rem 0.6rem;border-radius:999px;background:#ecfdf3;color:#166534;font-size:0.8rem;font-weight:700;">Read only • ${escapeSyncHtml(changedLabel)}</span>
            </div>
            ${note ? `<div style="margin-top:0.45rem;color:#7c2d12;font-size:0.9rem;">${escapeSyncHtml(note)}</div>` : ''}
            <div style="overflow:auto;margin-top:0.75rem;">
                <table style="width:100%;border-collapse:collapse;font-size:0.92rem;">
                    <thead>
                        <tr>
                            <th style="text-align:left;padding:0.5rem 0.6rem;color:#334155;">Field</th>
                            <th style="text-align:left;padding:0.5rem 0.6rem;color:#334155;">Queued edit</th>
                            <th style="text-align:left;padding:0.5rem 0.6rem;color:#334155;">Current record</th>
                        </tr>
                    </thead>
                    <tbody>${rowHtml}</tbody>
                </table>
            </div>
            <div style="margin-top:0.6rem;color:#7c2d12;font-size:0.88rem;">No auto-merge or delete action is available from this view.</div>
        </div>
    `;
}

function buildOutboxConflictRows(moduleKey, item, liveRecord = null) {
    const payload = item?.payload || {};
    const current = liveRecord && typeof liveRecord === 'object' ? liveRecord : {};

    if (moduleKey === 'members') {
        return {
            title: `Member edit • ${payload.member_code || item.action || 'unknown'}`,
            note: 'Compare the queued member mutation with the current member record before retrying.',
            rows: [
                { label: 'Record ID', queued: payload.id || item.id, current: current.id || '—', compare: false },
                { label: 'Member code', queued: payload.member_code, current: current.member_code },
                { label: 'Name', queued: payload.name, current: current.name },
                { label: 'Phone', queued: payload.phone, current: current.phone },
                { label: 'Join date', queued: payload.join_date, current: current.join_date || current.admission_date },
                { label: 'Membership type', queued: payload.membership_type, current: current.membership_type },
                { label: 'Status', queued: payload.status, current: current.status || current.calculated_status },
                { label: 'Expected updated at', queued: payload.expected_updated_at, current: current.updated_at },
                { label: 'Total due amount', queued: payload.total_due_amount, current: current.total_due_amount, type: 'money' }
            ]
        };
    }

    if (moduleKey === 'payments') {
        return {
            title: `Payment record • ${payload.member_code || payload.member_id || item.action || 'unknown'}`,
            note: 'Compare the queued payment with the current member balance and timestamp before retrying.',
            rows: [
                { label: 'Payment ID', queued: payload.id || item.id, current: current.id || '—', compare: false },
                { label: 'Member code', queued: payload.member_code, current: current.member_code },
                { label: 'Member name', queued: payload.member_name, current: current.name },
                { label: 'Amount', queued: payload.amount, current: null, type: 'money', compare: false },
                { label: 'Payment date', queued: payload.payment_date, current: null, compare: false },
                { label: 'Payment method', queued: payload.payment_method, current: null, compare: false },
                { label: 'Invoice number', queued: payload.invoice_number, current: null, compare: false },
                { label: 'Status', queued: payload.status, current: null, compare: false },
                { label: 'Expected updated at', queued: payload.expected_updated_at, current: current.updated_at },
                { label: 'Expected due amount', queued: payload.expected_total_due_amount, current: current.total_due_amount, type: 'money' }
            ]
        };
    }

    return {
        title: 'Conflict review',
        note: 'No compare data is available for this item.',
        rows: []
    };
}

async function loadOutboxConflictReview(moduleKey, itemId) {
    const container = document.getElementById(`outbox-conflict-review-${moduleKey}-${itemId}`);
    if (!container) return;

    const moduleState = getOfflineOutboxModuleState(moduleKey);
    const summary = moduleState.summary || {};
    const items = Array.isArray(summary.items) ? summary.items : [];
    const item = items.find(entry => String(entry.id) === String(itemId));
    if (!item) {
        container.style.display = 'block';
        container.innerHTML = '<div style="color:#b91c1c;">Queued item not found.</div>';
        return;
    }

    const payload = item.payload || {};
    const memberCode = String(payload.member_code || '').trim();
    container.style.display = 'block';
    container.innerHTML = '<div class="loading">Loading read-only compare…</div>';

    let liveRecord = null;
    let sourceLabel = 'Current live record';

    if (memberCode) {
        if (Utils.isOnline() && typeof lookupMemberByCodeAcrossGenders === 'function') {
            try {
                const lookup = await lookupMemberByCodeAcrossGenders(memberCode);
                if (lookup && lookup.success && lookup.data) {
                    liveRecord = lookup.data;
                    sourceLabel = `Live record (${lookup.gender || 'current'})`;
                }
            } catch (error) {
                console.error('Conflict compare lookup failed:', error);
            }
        }

        if (!liveRecord && typeof getCachedMemberProfileSnapshot === 'function') {
            const cachedSnapshot = getCachedMemberProfileSnapshot(memberCode);
            if (cachedSnapshot && cachedSnapshot.profile) {
                liveRecord = cachedSnapshot.profile;
                sourceLabel = `Cached snapshot (${cachedSnapshot.gender || 'member'})`;
            }
        }
    }

    const review = buildOutboxConflictRows(moduleKey, item, liveRecord);
    if (!liveRecord) {
        sourceLabel = 'No live record available; showing queued payload only.';
    }
    if (moduleKey === 'payments' && !liveRecord) {
        review.note = `${review.note} Live member data was not available, so only the queued payment is shown.`;
    }
    const changedFields = getOutboxConflictChangedFields(review.rows);
    if (changedFields.length) {
        sourceLabel = `${sourceLabel} • ${changedFields.length} changed field${changedFields.length === 1 ? '' : 's'}`;
        review.note = `${review.note} Changed fields: ${changedFields.slice(0, 4).join(', ')}${changedFields.length > 4 ? `, +${changedFields.length - 4} more` : ''}.`;
    }

    container.innerHTML = renderOutboxReviewTable(review.title, sourceLabel, review.rows, review.note);
    if (moduleKey === 'members') {
        const queuedButton = `<button type="button" class="btn btn-primary" onclick="openOutboxConflictResolution('${moduleKey}', '${item.id}', 'queued')">Start from queued edit</button>`;
        const currentButton = item.action === 'update' && liveRecord
            ? `<button type="button" class="btn btn-secondary" onclick="openOutboxConflictResolution('${moduleKey}', '${item.id}', 'current')">Start from current record</button>`
            : '';
        container.insertAdjacentHTML('beforeend', `<div style="margin-top:0.65rem;display:flex;gap:0.5rem;flex-wrap:wrap;">${queuedButton}${currentButton}</div>`);
    } else if (moduleKey === 'payments') {
        container.insertAdjacentHTML('beforeend', `<div style="margin-top:0.65rem;display:flex;gap:0.5rem;flex-wrap:wrap;"><button type="button" class="btn btn-primary" onclick="openOutboxConflictResolution('${moduleKey}', '${item.id}', 'queued')">Open payment form</button></div>`);
    }
}

function getOutboxConflictItem(moduleKey, itemId) {
    const moduleState = getOfflineOutboxModuleState(moduleKey);
    const summary = moduleState.summary || {};
    const items = Array.isArray(summary.items) ? summary.items : [];
    return items.find(entry => String(entry.id) === String(itemId)) || null;
}

function setConflictResolutionNote(elementId, message) {
    const note = document.getElementById(elementId);
    if (!note) return;
    if (!message) {
        note.style.display = 'none';
        note.textContent = '';
        return;
    }

    note.style.display = 'block';
    note.textContent = message;
}

function seedMemberResolutionForm(item, liveRecord, base = 'queued') {
    const payload = item?.payload || {};
    const isUpdate = item?.action === 'update';
    const resolvedGender = payload.gender || liveRecord?.gender || currentGender;
    setCurrentGender(resolvedGender);

    closeMemberModal();
    showAddMemberForm();

    document.querySelector('#memberModal .modal-header h2').textContent = base === 'current' ? 'Review Member Merge' : 'Resolve Member Conflict';
    document.getElementById('memberResolutionItemId').value = String(item.id || '');
    const baseRecord = base === 'current' && isUpdate && liveRecord ? liveRecord : payload;
    const overlayRecord = base === 'current' ? payload : liveRecord;

    document.getElementById('memberId').value = base === 'current' && isUpdate ? (liveRecord?.id || payload.id || '') : (isUpdate ? (payload.id || liveRecord?.id || '') : '');
    document.getElementById('memberCode').value = baseRecord.member_code || overlayRecord?.member_code || '';
    document.getElementById('memberName').value = baseRecord.name || overlayRecord?.name || '';
    document.getElementById('phone').value = baseRecord.phone || overlayRecord?.phone || '';
    document.getElementById('rfidUid').value = baseRecord.rfid_uid || overlayRecord?.rfid_uid || '';
    document.getElementById('email').value = baseRecord.email || overlayRecord?.email || '';
    document.getElementById('address').value = baseRecord.address || overlayRecord?.address || '';
    document.getElementById('joinDate').value = baseRecord.join_date || overlayRecord?.join_date || '';
    populateMembershipTypeOptions(baseRecord.membership_type || overlayRecord?.membership_type || '');
    document.getElementById('admissionFee').value = baseRecord.admission_fee ?? overlayRecord?.admission_fee ?? 0;
    document.getElementById('monthlyFee').value = baseRecord.monthly_fee ?? overlayRecord?.monthly_fee ?? 0;
    document.getElementById('trainerFee').value = baseRecord.ptf_fee ?? overlayRecord?.ptf_fee ?? 0;
    document.getElementById('lockerFee').value = baseRecord.locker_fee ?? overlayRecord?.locker_fee ?? 0;
    document.getElementById('nextFeeDueDate').value = baseRecord.next_fee_due_date || overlayRecord?.next_fee_due_date || '';
    document.getElementById('status').value = baseRecord.status || overlayRecord?.status || 'active';
    document.getElementById('memberUpdatedAt').value = base === 'current' && isUpdate ? (liveRecord?.updated_at || payload.expected_updated_at || '') : (isUpdate ? (liveRecord?.updated_at || payload.expected_updated_at || '') : '');
    document.getElementById('existingProfileImage').value = baseRecord.profile_image || overlayRecord?.profile_image || '';

    if (baseRecord.profile_image || overlayRecord?.profile_image) {
        const preview = document.getElementById('profileImagePreview');
        const previewImg = document.getElementById('previewImg');
        previewImg.src = baseRecord.profile_image || overlayRecord?.profile_image || '';
        preview.style.display = 'block';
    }

    const liveSummary = liveRecord
        ? `Current live member: ${liveRecord.member_code || 'unknown'} • updated ${liveRecord.updated_at || 'unknown'}`
        : 'No live record snapshot was loaded.';
    const modeLabel = isUpdate ? 'update' : 'create';
    const baseLabel = base === 'current' ? 'Current record values are loaded as the starting point.' : `Queued member ${modeLabel} values are loaded.`;
    setConflictResolutionNote('memberConflictResolutionNote', `${baseLabel} Review the compare panel before saving. ${liveSummary}`);
}

function seedPaymentResolutionForm(item, liveRecord) {
    const payload = item?.payload || {};
    const resolvedGender = payload.gender || liveRecord?.gender || currentGender;
    setCurrentGender(resolvedGender);

    closePaymentModal();
    showAddPaymentForm();

    document.querySelector('#paymentModal .modal-header h2').textContent = 'Resolve Payment Conflict';
    document.getElementById('paymentResolutionItemId').value = String(item.id || '');
    document.getElementById('paymentMemberCode').value = payload.member_code || liveRecord?.member_code || '';
    document.getElementById('paymentAmount').value = payload.amount ?? '';
    document.getElementById('paymentDate').value = payload.payment_date || '';
    document.getElementById('dueDate').value = payload.due_date || '';
    document.getElementById('invoiceNumber').value = payload.invoice_number || '';
    document.getElementById('paymentStatus').value = payload.status || 'completed';
    document.getElementById('paymentReceivedBy').value = payload.received_by || document.getElementById('paymentReceivedBy').value;
    document.getElementById('paymentMethod').value = payload.payment_method || 'Cash';

    const liveSummary = liveRecord
        ? `Current live balance: ${liveRecord.total_due_amount ?? 'unknown'} • updated ${liveRecord.updated_at || 'unknown'}`
        : 'No live member snapshot was loaded.';
    setConflictResolutionNote('paymentConflictResolutionNote', `Queued payment values are loaded. Recheck the member compare panel before saving. ${liveSummary}`);
}

async function openOutboxConflictResolution(moduleKey, itemId, resolutionBase = 'queued') {
    if (!['members', 'payments'].includes(moduleKey)) return;
    if (!Utils.isOnline()) {
        Utils.showNotification('Reconnect to resolve this conflict safely.', 'warning');
        return;
    }

    const item = getOutboxConflictItem(moduleKey, itemId);
    if (!item) {
        Utils.showNotification('Queued item not found.', 'error');
        return;
    }

    const payload = item.payload || {};
    const memberCode = String(payload.member_code || '').trim();
    if (!memberCode) {
        Utils.showNotification('This queued item is missing a member code and cannot be resolved safely.', 'error');
        return;
    }

    const lookup = await lookupMemberByCodeAcrossGenders(memberCode);
    if (!lookup.success || !lookup.data) {
        Utils.showNotification(lookup.message || 'Could not load the current member record.', 'error');
        return;
    }

    const liveRecord = { ...lookup.data, gender: lookup.gender };

    if (moduleKey === 'members') {
        seedMemberResolutionForm(item, liveRecord, resolutionBase);
        return;
    }

    seedPaymentResolutionForm(item, liveRecord);
}

function getOfflineOutboxModuleState(moduleKey) {
    const offlineState = window.OfflineState && typeof window.OfflineState.getModuleState === 'function'
        ? window.OfflineState.getModuleState(moduleKey)
        : {};

    if (moduleKey === 'attendance' && window.AttendanceOutbox && typeof window.AttendanceOutbox.getQueueSummary === 'function') {
        return {
            label: 'Attendance',
            summary: window.AttendanceOutbox.getQueueSummary(),
            retry: () => window.AttendanceOutbox.flushPending(),
            issue: offlineState.lastOutboxIssueMessage ? {
                at: offlineState.lastOutboxIssueAt || null,
                kind: offlineState.lastOutboxIssueKind || 'unknown',
                message: offlineState.lastOutboxIssueMessage,
                action: offlineState.lastOutboxIssueAction || null,
                source: offlineState.lastOutboxIssueSource || null
            } : null
        };
    }

    if (moduleKey === 'members' && window.MemberWriteOutbox && typeof window.MemberWriteOutbox.getQueueSummary === 'function') {
        return {
            label: 'Member writes',
            summary: window.MemberWriteOutbox.getQueueSummary(),
            retry: () => window.MemberWriteOutbox.flushPending(),
            issue: offlineState.lastOutboxIssueMessage ? {
                at: offlineState.lastOutboxIssueAt || null,
                kind: offlineState.lastOutboxIssueKind || 'unknown',
                message: offlineState.lastOutboxIssueMessage,
                action: offlineState.lastOutboxIssueAction || null,
                source: offlineState.lastOutboxIssueSource || null
            } : null
        };
    }

    if (moduleKey === 'payments' && window.PaymentOutbox && typeof window.PaymentOutbox.getQueueSummary === 'function') {
        return {
            label: 'Payments',
            summary: window.PaymentOutbox.getQueueSummary(),
            retry: () => window.PaymentOutbox.flushPending(),
            issue: offlineState.lastOutboxIssueMessage ? {
                at: offlineState.lastOutboxIssueAt || null,
                kind: offlineState.lastOutboxIssueKind || 'unknown',
                message: offlineState.lastOutboxIssueMessage,
                action: offlineState.lastOutboxIssueAction || null,
                source: offlineState.lastOutboxIssueSource || null
            } : null
        };
    }

    return {
        label: moduleKey,
        summary: { pendingCount: 0, failedCount: 0, items: [], online: Utils.isOnline(), persistenceMode: 'unknown' },
        retry: null,
        issue: offlineState.lastOutboxIssueMessage ? {
            at: offlineState.lastOutboxIssueAt || null,
            kind: offlineState.lastOutboxIssueKind || 'unknown',
            message: offlineState.lastOutboxIssueMessage,
            action: offlineState.lastOutboxIssueAction || null,
            source: offlineState.lastOutboxIssueSource || null
        } : null
    };
}

function renderOfflineOutboxModuleCard(moduleKey) {
    const moduleState = getOfflineOutboxModuleState(moduleKey);
    const summary = moduleState.summary || {};
    const items = Array.isArray(summary.items) ? summary.items : [];
    const pendingCount = Number(summary.pendingCount || 0);
    const failedCount = Number(summary.failedCount || items.filter(item => item.lastError).length || 0);
    const conflictItems = items.filter(item => item.lastErrorKind === 'conflict');
    const conflictCount = Number(summary.conflictCount || conflictItems.length || 0);
    const online = summary.online !== false && Utils.isOnline();
    const retryDisabled = !online || !moduleState.retry;
    const retryButton = `<button type="button" class="btn btn-primary" ${retryDisabled ? 'disabled' : ''} onclick="retryOfflineOutboxModule('${moduleKey}')">Retry now</button>`;
    const latestIssue = moduleState.issue;
    const latestIssueColor = latestIssue
        ? latestIssue.kind === 'conflict' || latestIssue.kind === 'dropped'
            ? '#991b1b'
            : '#b45309'
        : '#b45309';
    const visibleItemIds = new Set(items.slice(0, 3).map(item => String(item.id)));
    const itemRows = items.slice(0, 3).map(item => {
        const hasConflict = item.lastErrorKind === 'conflict';
        const itemError = item.lastError ? `<div style="margin-top:0.35rem;color:${hasConflict ? '#991b1b' : '#b91c1c'};">${escapeSyncHtml(item.lastError)}${item.lastErrorStatus ? ` <span style="font-size:0.82rem;">(HTTP ${Number(item.lastErrorStatus)})</span>` : ''}</div>` : '';
        const reviewButton = hasConflict && (moduleKey === 'members' || moduleKey === 'payments')
            ? `<button type="button" class="btn btn-secondary" style="margin-top:0.5rem;" onclick="loadOutboxConflictReview('${moduleKey}', '${item.id}')">Review compare</button><button type="button" class="btn btn-primary" style="margin-top:0.5rem;margin-left:0.5rem;" onclick="openOutboxConflictResolution('${moduleKey}', '${item.id}')">Resolve in form</button>`
            : '';
        const reviewPanel = hasConflict && (moduleKey === 'members' || moduleKey === 'payments')
            ? `<div id="outbox-conflict-review-${moduleKey}-${item.id}" style="display:none;"></div>`
            : '';
        return `
            <div style="padding:0.75rem 0;border-top:1px solid rgba(148,163,184,0.18);">
                <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:center;">
                    <strong>${escapeSyncHtml(item.action || 'item')} • ${escapeSyncHtml(formatOfflineOutboxAge(item.createdAt))}</strong>
                    <span style="font-size:0.85rem;color:var(--text-secondary);">Attempts: ${Number(item.attempts || 0)}</span>
                </div>
                <div style="margin-top:0.25rem;color:var(--text-secondary);font-size:0.9rem;">Source: ${escapeSyncHtml(item.source || 'outbox')}</div>
                ${itemError}
                ${hasConflict ? '<div style="margin-top:0.35rem;color:#7c2d12;font-size:0.9rem;font-weight:700;">Conflict retained for manual review.</div>' : ''}
                ${reviewButton}
                ${reviewPanel}
            </div>
        `;
    }).join('');
    const conflictReviewRows = conflictItems
        .filter(item => !visibleItemIds.has(String(item.id)))
        .slice(0, 5)
        .map(item => `
        <div style="padding:0.8rem 0;border-top:1px solid rgba(148,163,184,0.18);">
            <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:center;">
                <strong style="color:#7c2d12;">${escapeSyncHtml(item.action || 'item')} • ${escapeSyncHtml(formatOfflineOutboxAge(item.createdAt))}</strong>
                <span style="font-size:0.85rem;color:#7c2d12;">${item.lastErrorStatus ? `HTTP ${Number(item.lastErrorStatus)}` : 'Conflict'}</span>
            </div>
            <div style="margin-top:0.25rem;color:#7c2d12;font-size:0.9rem;">${escapeSyncHtml(item.lastError || 'Conflict needs review')}</div>
            ${(moduleKey === 'members' || moduleKey === 'payments') ? `<button type="button" class="btn btn-secondary" style="margin-top:0.5rem;" onclick="loadOutboxConflictReview('${moduleKey}', '${item.id}')">Review compare</button><button type="button" class="btn btn-primary" style="margin-top:0.5rem;margin-left:0.5rem;" onclick="openOutboxConflictResolution('${moduleKey}', '${item.id}')">Resolve in form</button><div id="outbox-conflict-review-${moduleKey}-${item.id}" style="display:none;"></div>` : ''}
        </div>
    `).join('');

    return `
        <div style="padding:1rem 1.15rem;border:1px solid ${pendingCount > 0 ? '#f59e0b' : '#bbf7d0'};border-radius:12px;background:${pendingCount > 0 ? '#fffbeb' : 'var(--bg-secondary)'};">
            <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:flex-start;">
                <div>
                    <div style="font-size:0.82rem;text-transform:uppercase;letter-spacing:0.08em;color:#166534;font-weight:700;">${escapeSyncHtml(moduleState.label)}</div>
                    <h4 style="margin:0.35rem 0 0;">${pendingCount} pending${failedCount ? `, ${failedCount} with errors` : ''}${conflictCount ? `, ${conflictCount} need review` : ''}</h4>
                    <p style="margin:0.45rem 0 0;color:var(--text-secondary);">${summary.persistenceMode === 'session' ? 'Session-only queue.' : 'Stored locally until replay.'}${conflictCount ? ' Conflicting edits stay queued until you review or resolve them.' : ''}</p>
                    ${latestIssue ? `<p style="margin:0.35rem 0 0;color:${latestIssueColor};">Last issue: ${escapeSyncHtml(latestIssue.message || 'Unknown error')}</p>` : ''}
                </div>
                <div style="display:flex;flex-direction:column;align-items:flex-end;gap:0.5rem;">
                    <span style="padding:0.35rem 0.7rem;border-radius:999px;background:${pendingCount > 0 ? '#fef3c7' : '#dcfce7'};color:${pendingCount > 0 ? '#92400e' : '#166534'};font-weight:700;">${pendingCount} pending${conflictCount ? ` • ${conflictCount} review` : ''}</span>
                    ${retryButton}
                </div>
            </div>
            <div style="margin-top:0.9rem;">
                ${items.length ? itemRows : '<div style="color:var(--text-secondary);">No queued items right now.</div>'}
            </div>
            ${conflictCount ? `
            <div style="margin-top:1rem;padding-top:0.75rem;border-top:1px solid rgba(148,163,184,0.18);">
                <strong style="color:#7c2d12;">Conflict review queue</strong>
                <p style="margin:0.35rem 0 0;color:#7c2d12;font-size:0.9rem;">These queued edits need a human compare step before retrying.</p>
                ${conflictReviewRows || '<div style="margin-top:0.5rem;color:var(--text-secondary);">Conflict items are already shown above.</div>'}
                ${conflictItems.length > 5 ? `<div style="margin-top:0.5rem;color:#7c2d12;font-size:0.88rem;">... and ${conflictItems.length - 5} more conflict${conflictItems.length - 5 === 1 ? '' : 's'} pending review.</div>` : ''}
            </div>` : ''}
        </div>
    `;
}

function loadOfflineOutbox() {
    const container = document.getElementById('offlineOutboxSummary');
    if (!container) return;

    const sections = ['attendance', 'members', 'payments'].map(renderOfflineOutboxModuleCard).join('');
    const retryAllDisabled = !Utils.isOnline();
    container.innerHTML = `
        <div class="section-card" style="margin-top:1rem;">
            <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:center;">
                <div>
                    <h3 style="margin:0;">Offline outbox</h3>
                    <p style="margin:0.35rem 0 0;color:var(--text-secondary);">Queued attendance, member, and payment writes live here. Conflicting member/payment edits stay queued until you resolve them manually. Nothing is auto-merged.</p>
                </div>
                <button type="button" class="btn btn-secondary" ${retryAllDisabled ? 'disabled' : ''} onclick="retryAllOfflineOutbox()">Retry all</button>
            </div>
            <div style="display:grid;gap:0.85rem;margin-top:1rem;">${sections}</div>
        </div>
    `;
}

function refreshOfflineOutboxIfVisible() {
    if (currentSection !== 'sync') return;
    loadOfflineOutbox();
}

function bindOfflineOutboxRefresh() {
    if (offlineOutboxRefreshBound) return;
    offlineOutboxRefreshBound = true;

    const refresh = () => refreshOfflineOutboxIfVisible();
    window.addEventListener('attendance-outbox:changed', refresh);
    window.addEventListener('attendance-outbox:flush-end', refresh);
    window.addEventListener('member-write-outbox:changed', refresh);
    window.addEventListener('member-write-outbox:flush-end', refresh);
    window.addEventListener('payment-outbox:changed', refresh);
    window.addEventListener('payment-outbox:flush-end', refresh);
    window.addEventListener('online', refresh);
    window.addEventListener('offline', refresh);
}

function retryOfflineOutboxModule(moduleKey) {
    if (!Utils.isOnline()) return;

    if (moduleKey === 'attendance' && window.AttendanceOutbox && typeof window.AttendanceOutbox.flushPending === 'function') {
        window.AttendanceOutbox.flushPending().then(refreshOfflineOutboxIfVisible).catch(err => console.error('Attendance retry failed:', err));
        return;
    }

    if (moduleKey === 'members' && window.MemberWriteOutbox && typeof window.MemberWriteOutbox.flushPending === 'function') {
        window.MemberWriteOutbox.flushPending().then(refreshOfflineOutboxIfVisible).catch(err => console.error('Member retry failed:', err));
        return;
    }

    if (moduleKey === 'payments' && window.PaymentOutbox && typeof window.PaymentOutbox.flushPending === 'function') {
        window.PaymentOutbox.flushPending().then(refreshOfflineOutboxIfVisible).catch(err => console.error('Payment retry failed:', err));
    }
}

function retryAllOfflineOutbox() {
    if (!Utils.isOnline()) return;

    const sequence = Promise.resolve()
        .then(() => window.AttendanceOutbox && typeof window.AttendanceOutbox.flushPending === 'function' ? window.AttendanceOutbox.flushPending() : null)
        .then(() => window.MemberWriteOutbox && typeof window.MemberWriteOutbox.flushPending === 'function' ? window.MemberWriteOutbox.flushPending() : null)
        .then(() => window.PaymentOutbox && typeof window.PaymentOutbox.flushPending === 'function' ? window.PaymentOutbox.flushPending() : null);

    sequence.then(refreshOfflineOutboxIfVisible).catch(err => console.error('Retry all outbox failed:', err));
}

function loadSync() {
    const isOnline = !window.location.hostname.includes('localhost') && !window.location.hostname.includes('127.0.0.1');

    const html = `
        <div class="sync-section">
            ${renderSectionGuideCard({
                chip: 'Sync Help',
                title: 'Use sync only when needed',
                description: 'This is not a normal daily button for most staff. Use it only when you need to send or receive data between local and online systems.',
                steps: [
                    'If you are not sure, stop and ask before syncing.',
                    'Watch the result box after every sync.',
                    'Use Retry Failed when some records show an error reason below.'
                ]
            })}
            <div class="section-header">
                <h2>Send / Download Data</h2>
                <div class="section-actions">
                    ${isOnline
            ? '<button class="btn btn-primary" id="reverseSyncBtn">⬇️ Download to Local</button>'
            : '<button class="btn btn-primary" id="syncNowBtn">🔄 Send to Online</button><button class="btn btn-secondary" id="retryFailedSyncBtn">↺ Retry Failed Only</button>'}
                </div>
            </div>
            <div style="background: var(--bg-secondary); color: var(--text-color); padding: 1.5rem; border-radius: 10px; box-shadow: var(--shadow); margin-bottom: 1.5rem; border: 1px solid var(--border-color);">
                <h3 style="color: var(--text-color);">Current Status</h3>
                <div id="syncStatus" style="margin-top: 1rem; color: var(--text-secondary);">
                    <p>${isOnline
            ? 'Click "Download to Local" to copy online data into your local database.'
            : 'Click "Send to Online" to upload local data to the online server. Use Retry Failed Only if some records already failed.'}</p>
                </div>
            </div>
            <div id="offlineOutboxSummary"></div>
            <div style="display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));">
                <div style="background: var(--bg-secondary); color: var(--text-color); padding: 1.5rem; border-radius: 10px; box-shadow: var(--shadow); border: 1px solid var(--border-color);">
                    <h3 style="color: var(--text-color);">Recent Activity</h3>
                    <div id="syncHistory" style="margin-top: 1rem;">
                        <div class="loading">Loading sync history...</div>
                    </div>
                </div>
                ${isOnline ? '' : `
                <div style="background: var(--bg-secondary); color: var(--text-color); padding: 1.5rem; border-radius: 10px; box-shadow: var(--shadow); border: 1px solid var(--border-color);">
                    <h3 style="color: var(--text-color);">Failed Records</h3>
                    <div id="failedSyncRecords" style="margin-top: 1rem; color: var(--text-secondary);">
                        <div class="loading">Loading failed records...</div>
                    </div>
                </div>`}
            </div>
        </div>
    `;
    document.getElementById('contentBody').innerHTML = html;

    const reverseSyncBtn = document.getElementById('reverseSyncBtn');
    if (reverseSyncBtn) reverseSyncBtn.addEventListener('click', performReverseSync);

    const syncBtn = document.getElementById('syncNowBtn');
    if (syncBtn) syncBtn.addEventListener('click', () => performSync(false));

    const retryBtn = document.getElementById('retryFailedSyncBtn');
    if (retryBtn) retryBtn.addEventListener('click', () => performSync(true));

    loadOfflineOutbox();
    loadSyncHistory();
    loadFailedSyncRecords();
}

function escapeSyncHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function renderSyncStatusCard(type, title, data = {}) {
    const isSuccess = type === 'success';
    const color = isSuccess ? '#166534' : '#DC2626';
    const background = isSuccess ? '#ECFDF3' : '#FEF2F2';
    const border = isSuccess ? '#BBF7D0' : '#FECACA';
    const synced = data.total_synced || 0;
    const failed = data.total_failed || 0;
    const errors = Array.isArray(data.errors) ? data.errors : [];
    const note = data.message || '';

    return `
        <div style="padding: 1rem; background: ${background}; border-radius: 10px; color: var(--text-color); border: 1px solid ${border};">
            <strong style="color: ${color};">${escapeSyncHtml(title)}</strong>
            ${note ? `<p style="margin: 0.5rem 0 0 0; color: var(--text-secondary);">${escapeSyncHtml(note)}</p>` : ''}
            ${typeof data.total_synced !== 'undefined' ? `<p style="margin: 0.5rem 0 0 0;">Records Synced: <strong style="color: var(--text-color);">${synced}</strong></p>` : ''}
            ${typeof data.total_failed !== 'undefined' ? `<p style="margin: 0.35rem 0 0 0;">Records Failed: <strong style="color: ${failed > 0 ? '#DC2626' : '#166534'};">${failed}</strong></p>` : ''}
            ${errors.length ? `
                <div style="margin-top: 0.75rem;">
                    <strong style="color: #B45309;">Main error reasons:</strong>
                    <ul style="margin: 0.35rem 0 0 1rem; color: var(--text-secondary);">
                        ${errors.slice(0, 5).map(error => `<li>${escapeSyncHtml(error)}</li>`).join('')}
                        ${errors.length > 5 ? `<li>... and ${errors.length - 5} more</li>` : ''}
                    </ul>
                </div>` : ''}
        </div>
    `;
}

function setSyncButtonsBusy(isBusy, isRetry = false) {
    const syncBtn = document.getElementById('syncNowBtn');
    const retryBtn = document.getElementById('retryFailedSyncBtn');

    if (syncBtn) {
        syncBtn.disabled = isBusy;
        syncBtn.textContent = isBusy && !isRetry ? 'Working...' : '🔄 Send to Online';
    }

    if (retryBtn) {
        retryBtn.disabled = isBusy;
        retryBtn.textContent = isBusy && isRetry ? 'Working...' : '↺ Retry Failed Only';
    }
}

async function fetchSyncJson(url) {
    const res = await fetch(url);
    const text = await res.text();
    if (!res.ok) {
        throw new Error(`HTTP ${res.status}: ${text.substring(0, 200)}`);
    }
    if (!text || !text.trim()) {
        throw new Error('Empty response from server');
    }
    try {
        return JSON.parse(text);
    } catch (e) {
        throw new Error('Invalid JSON response: ' + text.substring(0, 100));
    }
}

async function performSync(retryFailedOnly = false) {
    const syncStatus = document.getElementById('syncStatus');
    setSyncButtonsBusy(true, retryFailedOnly);

    if (syncStatus) {
        syncStatus.innerHTML = `<div class="loading">${retryFailedOnly ? 'Retrying failed records only...' : 'Sending data to online server...'}</div>`;
    }

    try {
        const url = retryFailedOnly ? 'api/sync-local.php?type=manual&retry_failed=1' : 'api/sync-local.php?type=manual';
        const data = await fetchSyncJson(url);
        setSyncButtonsBusy(false, retryFailedOnly);

        if (!data || !data.success) {
            Utils.showNotification(data?.message || 'Sync failed', 'error');
            if (syncStatus) {
                syncStatus.innerHTML = renderSyncStatusCard('error', retryFailedOnly ? '❌ Retry failed' : '❌ Data send failed', {
                    message: data?.message || 'Unknown error'
                });
            }
            loadSyncHistory();
            loadFailedSyncRecords();
            return;
        }

        if (!retryFailedOnly && (data.total_synced || 0) === 0 && (data.total_failed || 0) === 0) {
            const forceSync = confirm('No records were sent this time. This may mean everything is already marked as sent, even if some data is missing online.\n\nDo you want to send everything again? This ignores previous sync history.');
            if (forceSync) {
                setSyncButtonsBusy(true, false);
                const forceData = await fetchSyncJson('api/sync-local.php?type=manual&force=1');
                setSyncButtonsBusy(false, false);
                Utils.showNotification(forceData?.success ? 'Full resend completed' : 'Full resend failed', forceData?.success ? 'success' : 'error');
                if (syncStatus) {
                    syncStatus.innerHTML = renderSyncStatusCard(forceData?.success ? 'success' : 'error', forceData?.success ? '✅ Full resend completed' : '❌ Full resend failed', forceData || {});
                }
                loadSyncHistory();
                loadFailedSyncRecords();
                return;
            }
        }

        Utils.showNotification(data.message || (retryFailedOnly ? 'Failed records retried' : 'Data send completed successfully'), 'success');
        if (syncStatus) {
            syncStatus.innerHTML = renderSyncStatusCard('success', retryFailedOnly ? '✅ Retry failed completed' : '✅ Data send completed', data);
        }
        loadSyncHistory();
        loadFailedSyncRecords();
    } catch (err) {
        console.error('Sync error:', err);
        setSyncButtonsBusy(false, retryFailedOnly);
        Utils.showNotification((retryFailedOnly ? 'Retry failed error: ' : 'Error during sync: ') + err.message, 'error');
        if (syncStatus) {
            syncStatus.innerHTML = renderSyncStatusCard('error', retryFailedOnly ? '❌ Retry failed error' : '❌ Data send error', {
                message: err.message
            });
        }
        loadFailedSyncRecords();
    }
}

async function performReverseSync() {
    const syncBtn = document.getElementById('reverseSyncBtn');
    const syncStatus = document.getElementById('syncStatus');

    if (syncBtn) {
        syncBtn.disabled = true;
        syncBtn.textContent = 'Working...';
    }

    if (syncStatus) {
        syncStatus.innerHTML = '<div class="loading">Downloading data from online to local database...</div>';
    }

    try {
        const data = await fetchSyncJson('api/sync-online-to-local.php?type=manual');
        if (syncBtn) {
            syncBtn.disabled = false;
            syncBtn.textContent = '⬇️ Download to Local';
        }

        if (data && data.success) {
            Utils.showNotification(data.message || 'Download to local completed successfully', 'success');
            if (syncStatus) {
                syncStatus.innerHTML = renderSyncStatusCard('success', '✅ Download to local completed', data);
            }
            loadSyncHistory();
            return;
        }

        Utils.showNotification(data?.message || 'Download to local failed', 'error');
        if (syncStatus) {
            syncStatus.innerHTML = renderSyncStatusCard('error', '❌ Download to local failed', {
                message: data?.message || 'Unknown error',
                errors: data?.solutions || []
            });
        }
    } catch (err) {
        console.error('Reverse sync error:', err);
        if (syncBtn) {
            syncBtn.disabled = false;
            syncBtn.textContent = '⬇️ Download to Local';
        }
        Utils.showNotification('Download to local error: ' + err.message, 'error');
        if (syncStatus) {
            syncStatus.innerHTML = renderSyncStatusCard('error', '❌ Download to local error', {
                message: err.message
            });
        }
    }
}

async function loadSyncHistory() {
    const syncHistory = document.getElementById('syncHistory');
    if (!syncHistory) return;

    syncHistory.innerHTML = '<div class="loading">Loading sync history...</div>';

    try {
        const data = await fetchSyncJson('api/sync-history.php?limit=8');
        const sessions = Array.isArray(data?.data) ? data.data : [];

        if (!sessions.length) {
            syncHistory.innerHTML = '<p style="color: var(--text-secondary);">No recent send/download activity yet.</p>';
            return;
        }

        syncHistory.innerHTML = sessions.map(session => {
            const statusColor = session.status === 'completed' ? '#166534' : session.status === 'failed' ? '#DC2626' : '#B45309';
            return `
                <div style="padding: 0.85rem 0; border-bottom: 1px solid #BBF7D0;">
                    <div style="display: flex; justify-content: space-between; gap: 1rem; flex-wrap: wrap; align-items: center;">
                        <div>
                            <strong style="color: var(--text-color);">${escapeSyncHtml((session.session_type || 'sync').replace(/_/g, ' '))}</strong>
                            <div style="font-size: 0.9rem; color: var(--text-secondary); margin-top: 0.2rem;">Started: ${escapeSyncHtml(session.started_at || 'N/A')}</div>
                            <div style="font-size: 0.9rem; color: var(--text-secondary);">Finished: ${escapeSyncHtml(session.completed_at || 'Still running')}</div>
                        </div>
                        <div style="text-align: right;">
                            <div style="font-weight: 700; color: ${statusColor}; text-transform: capitalize;">${escapeSyncHtml(session.status || 'unknown')}</div>
                            <div style="font-size: 0.9rem; color: var(--text-secondary);">Sent: ${Number(session.records_synced || 0)} | Failed: ${Number(session.records_failed || 0)}</div>
                        </div>
                    </div>
                    ${session.error_message ? `<div style="margin-top: 0.5rem; color: #B45309; font-size: 0.9rem; white-space: pre-line;">${escapeSyncHtml(session.error_message)}</div>` : ''}
                </div>
            `;
        }).join('');
    } catch (err) {
        syncHistory.innerHTML = `<div class="error">Could not load sync history: ${escapeSyncHtml(err.message)}</div>`;
    }
}

async function loadFailedSyncRecords() {
    const failedContainer = document.getElementById('failedSyncRecords');
    if (!failedContainer) return;

    failedContainer.innerHTML = '<div class="loading">Loading failed records...</div>';

    try {
        const response = await fetchSyncJson('api/sync-local.php?action=failed_records&limit=20');
        const payload = response?.data || {};
        const summary = Array.isArray(payload.summary) ? payload.summary : [];
        const records = Array.isArray(payload.records) ? payload.records : [];

        if (!records.length) {
            failedContainer.innerHTML = '<p style="color: var(--text-color);">No failed records right now. Good.</p>';
            return;
        }

        failedContainer.innerHTML = `
            ${summary.length ? `<div style="display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 1rem;">${summary.map(item => `<span style="padding: 0.35rem 0.65rem; background: #F3F7F4; border: 1px solid #BBF7D0; border-radius: 999px; color: #14532D; font-size: 0.9rem;">${escapeSyncHtml(item.table_name)}: <strong>${Number(item.failed_count || 0)}</strong></span>`).join('')}</div>` : ''}
            <div style="display: grid; gap: 0.75rem;">
                ${records.map(record => `
                    <div style="padding: 0.9rem; border: 1px solid #FECACA; background: #FEF2F2; border-radius: 10px;">
                        <div style="display: flex; justify-content: space-between; gap: 1rem; flex-wrap: wrap; align-items: center;">
                            <strong style="color: var(--text-color);">${escapeSyncHtml(record.table_name)} #${Number(record.record_id || 0)}</strong>
                            <span style="font-size: 0.85rem; color: #B45309;">Attempts: ${Number(record.sync_attempts || 0)}</span>
                        </div>
                        <div style="margin-top: 0.35rem; color: var(--text-secondary); font-size: 0.92rem;">${escapeSyncHtml(record.record_summary || 'Record summary unavailable')}</div>
                        <div style="margin-top: 0.45rem; color: #DC2626; font-size: 0.92rem;"><strong>Reason:</strong> ${escapeSyncHtml(record.last_error || 'Unknown error')}</div>
                        <div style="margin-top: 0.35rem; color: var(--text-secondary); font-size: 0.85rem;">Last try: ${escapeSyncHtml(record.updated_at || 'N/A')}</div>
                    </div>
                `).join('')}
            </div>
        `;
    } catch (err) {
        failedContainer.innerHTML = `<div class="error">Could not load failed records: ${escapeSyncHtml(err.message)}</div>`;
    }
}

// Auto-sync timer (every 30 minutes)
let autoSyncInterval = null;

function startAutoSync() {
    // Clear existing interval if any
    if (autoSyncInterval) {
        clearInterval(autoSyncInterval);
    }

    // Auto-sync every 30 minutes (1800000 ms)
    autoSyncInterval = setInterval(() => {
        if (!Utils.isOnline()) return;
        // Auto-sync triggered
        fetch('api/sync-local.php?type=auto')
            .then(async res => {
                const text = await res.text();
                if (res.ok) {
                    const data = JSON.parse(text);
                    if (data.success) {
                        // Auto-sync completed successfully
                        // Only show notification if on sync page
                        if (currentSection === 'sync') {
                            Utils.showNotification('Auto-sync completed: ' + (data.total_synced || 0) + ' records synced', 'success');
                            loadSyncHistory();
                        }
                    }
                }
            })
            .catch(err => {
                console.error('Auto-sync error:', err);
            });
    }, 1800000); // 30 minutes
}


async function handleLogout() {
    // Stop auto-sync on logout
    if (autoSyncInterval) {
        clearInterval(autoSyncInterval);
    }

    stopSectionAutoRefresh();
    try {
        await fetch('api/auth.php?action=logout', {
            method: 'POST',
            keepalive: true
        });
    } catch (err) {
        console.error('Logout error:', err);
    } finally {
        localStorage.clear();
        sessionStorage.removeItem('gym_last_role');
        sessionStorage.removeItem('gym_last_username');
        await Utils.clearSensitiveCaches();
        window.location.replace('index.html');
    }
}


// ==========================================
// GLOBAL SCAN SEARCH
// ==========================================
let isGlobalScanning = false;
let globalScanInterval = null;

function toggleGlobalSearchScan() {
    if (isGlobalScanning) {
        stopGlobalSearchScan();
        return;
    }

    // Create and show overlay
    const overlayHtml = `
        <div id="scanSearchOverlay" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(243,247,244,0.96); z-index: 9999; display: flex; flex-direction: column; align-items: center; justify-content: center; color: var(--text-color);">
            <div style="font-size: 4rem; color: #2196F3; margin-bottom: 20px;">
                <i class="fas fa-wifi fa-pulse"></i>
            </div>
            <h2 style="margin-bottom: 10px;">Waiting for member card...</h2>
            <p style="font-size: 1.2rem; color: #ccc;">Tap or scan the member card on the desk scanner to open profile.</p>
            <button class="btn btn-secondary" onclick="stopGlobalSearchScan()" style="margin-top: 30px; padding: 10px 30px; font-size: 1.1rem;">Close</button>
        </div>
    `;
    document.body.insertAdjacentHTML('beforeend', overlayHtml);
    isGlobalScanning = true;

    // Start polling
    let attempts = 0;
    const maxAttempts = 60; // 1 minute timeout

    globalScanInterval = setInterval(() => {
        attempts++;
        if (attempts >= maxAttempts) {
            stopGlobalSearchScan();
            Utils.showNotification('Scan timed out', 'info');
            return;
        }

        fetch('api/rfid-assign.php?action=get_latest')
            .then(res => res.json())
            .then(data => {
                if (data.success && data.found && data.uid) {
                    const now = Math.floor(Date.now() / 1000);
                    // Only accept very recent scans (last 3 seconds) to avoid picking up old cached ones immediately
                    if (now - data.timestamp < 3) {
                        handleGlobalScanSuccess(data.uid);
                    }
                }
            })
            .catch(err => console.error('Global scan poll error:', err));
    }, 1000);
}

function stopGlobalSearchScan() {
    isGlobalScanning = false;
    clearInterval(globalScanInterval);
    const overlay = document.getElementById('scanSearchOverlay');
    if (overlay) overlay.remove();
}

function handleGlobalScanSuccess(uid) {
    stopGlobalSearchScan();
    Utils.showNotification('Card detected! Searching...', 'info');

    // Try finding in 'men' first
    fetch(`api/members.php?action=getByRfid&rfid_uid=${uid}&gender=men`)
        .then(res => res.json())
        .then(data => {
            if (data.success && data.data) {
                openMemberProfile(data.data.member_code, 'men');
            } else {
                // Not found in men, try women
                return fetch(`api/members.php?action=getByRfid&rfid_uid=${uid}&gender=women`)
                    .then(res => res.json())
                    .then(data => {
                        if (data.success && data.data) {
                            openMemberProfile(data.data.member_code, 'women');
                        } else {
                            Utils.showNotification('Member not found with this RFID card', 'error');
                        }
                    });
            }
        })
        .catch(err => {
            console.error('Search error:', err);
            Utils.showNotification('Error searching for member', 'error');
        });
}

// ==========================================
// CAMERA CAPTURE
// ==========================================
let cameraStream = null;

function startCamera() {
    // Check if browser supports media devices
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        Utils.showNotification('Camera Access not supported by this browser.', 'error');
        return;
    }

    // Create modal logic
    const html = `
        <div class="modal" id="cameraModal" style="display: flex;">
            <div class="modal-content" style="max-width: 640px; width: 100%;">
                <div class="modal-header">
                    <h2>Capture Photo</h2>
                    <button class="modal-close" onclick="stopCamera()">&times;</button>
                </div>
                <div class="modal-body" style="text-align: center;">
                    <video id="cameraVideo" autoplay playsinline style="width: 100%; max-height: 400px; background: var(--bg-secondary); border-radius: 8px;"></video>
                    <canvas id="cameraCanvas" style="display: none;"></canvas>
                </div>
                <div class="modal-footer" style="justify-content: center;">
                    <button type="button" class="btn btn-primary" onclick="capturePhoto()" style="font-size: 1.2rem; padding: 10px 30px;">
                        <i class="fas fa-camera"></i> Take Photo
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="stopCamera()">Cancel</button>
                </div>
            </div>
        </div>
    `;
    document.body.insertAdjacentHTML('beforeend', html);

    // Start stream
    const video = document.getElementById('cameraVideo');
    navigator.mediaDevices.getUserMedia({ video: true })
        .then(stream => {
            cameraStream = stream;
            video.srcObject = stream;
        })
        .catch(err => {
            console.error('Camera Error:', err);
            Utils.showNotification('Could not access camera: ' + err.message, 'error');
            stopCamera(); // Cleanup modal if error
        });
}

function stopCamera() {
    if (cameraStream) {
        cameraStream.getTracks().forEach(track => track.stop());
        cameraStream = null;
    }
    const modal = document.getElementById('cameraModal');
    if (modal) modal.remove();
}

function capturePhoto() {
    const video = document.getElementById('cameraVideo');
    const canvas = document.getElementById('cameraCanvas');

    if (!video || !canvas) return;

    // Set canvas dimensions to match video
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;

    // Draw frame
    const context = canvas.getContext('2d');
    context.drawImage(video, 0, 0, canvas.width, canvas.height);

    // Convert to file
    canvas.toBlob(blob => {
        const file = new File([blob], "profile_capture.jpg", { type: "image/jpeg" });

        // Update file input
        const fileInput = document.getElementById('profileImage');
        const dataTransfer = new DataTransfer();
        dataTransfer.items.add(file);
        fileInput.files = dataTransfer.files;

        // Trigger change event for preview
        const event = new Event('change', { bubbles: true });
        fileInput.dispatchEvent(event);

        stopCamera();
        Utils.showNotification('Photo captured!', 'success');
    }, 'image/jpeg', 0.99); // 99% quality
}

/* ========================= Packages (membership plans) ========================= */
function packageDurationLabel(months) {
    months = parseInt(months) || 1;
    if (months === 12) return '12 months (1 year)';
    if (months % 12 === 0) return months + ' months (' + (months / 12) + ' years)';
    return months + (months === 1 ? ' month' : ' months');
}

function packageEscHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function loadPackages() {
    const contentBody = document.getElementById('contentBody');
    if (!contentBody) return;
    contentBody.innerHTML = `
        <div class="section-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;margin-bottom:1rem;">
            <div>
                <h2 style="margin:0;">Membership Packages</h2>
                <p style="margin:.25rem 0 0;color:var(--text-secondary);">Set up the plans your gym sells — monthly and any other packages.</p>
            </div>
            ${isAdminUser() ? '<button class="btn btn-primary" onclick="showAddPackageForm()">+ Add Package</button>' : ''}
        </div>
        <div id="packagesTableContainer"><div class="loading">Loading packages…</div></div>
    `;
    loadPackagesTable();
}

function loadPackagesTable() {
    fetch('api/packages.php?action=list&limit=200')
        .then(async res => {
            if (!res.ok) throw new Error('Failed to load packages');
            return JSON.parse(await res.text());
        })
        .then(data => {
            if (data.success) renderPackagesTable(data.data || []);
            else Utils.showNotification(data.message || 'Failed to load packages', 'error');
        })
        .catch(err => {
            console.error('Packages load error:', err);
            const c = document.getElementById('packagesTableContainer');
            if (c) c.innerHTML = '<div class="error">Could not load packages.</div>';
        });
}

function renderPackagesTable(packages) {
    const container = document.getElementById('packagesTableContainer');
    if (!container) return;
    if (!packages || packages.length === 0) {
        container.innerHTML = `<div class="empty-state" style="text-align:center;padding:2rem;color:var(--text-secondary);">
            <strong style="display:block;margin-bottom:.35rem;">No packages yet</strong>
            ${isAdminUser() ? 'Click “Add Package” to create your first plan (e.g. Monthly).' : 'No packages have been set up yet.'}
        </div>`;
        return;
    }
    const rows = packages.map((p, idx) => `
        <tr>
            <td data-label="#">${idx + 1}</td>
            <td data-label="Package"><strong>${packageEscHtml(p.name)}</strong></td>
            <td data-label="Duration">${packageDurationLabel(p.duration_months)}</td>
            <td data-label="Price"><strong>${Utils.formatCurrency(p.price || 0)}</strong></td>
            <td data-label="Admission Fee">${parseFloat(p.admission_fee) > 0 ? Utils.formatCurrency(p.admission_fee) : '—'}</td>
            <td data-label="Status">${parseInt(p.is_active) ? '<span class="status-badge status-active">Active</span>' : '<span class="status-badge status-inactive">Inactive</span>'}</td>
            <td data-label="Actions">
                ${isAdminUser() ? `
                    <button class="btn btn-sm btn-primary" onclick="showEditPackageForm(${p.id})">Edit</button>
                    <button class="btn btn-sm btn-danger" onclick="deletePackage(${p.id})">Delete</button>
                ` : '<span style="color:#6b7280;">Read only</span>'}
            </td>
        </tr>
    `).join('');
    container.innerHTML = `
        <table class="data-table">
            <thead>
                <tr><th>#</th><th>Package</th><th>Duration</th><th>Price</th><th>Admission Fee</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>${rows}</tbody>
        </table>
    `;
}

function showAddPackageForm() {
    if (!requireAdminAccess('add packages')) return;
    showPackageForm(null);
}

function showEditPackageForm(id) {
    if (!requireAdminAccess('edit packages')) return;
    fetch('api/packages.php?action=get&id=' + encodeURIComponent(id))
        .then(async res => JSON.parse(await res.text()))
        .then(data => {
            if (data.success && data.data) showPackageForm(data.data);
            else Utils.showNotification(data.message || 'Could not load package.', 'error');
        })
        .catch(err => {
            console.error('Package load error:', err);
            Utils.showNotification('Could not load package for editing.', 'error');
        });
}

function showPackageForm(pkg) {
    const isEdit = !!pkg;
    const active = isEdit ? parseInt(pkg.is_active) : 1;
    const html = `
        <div class="modal" id="packageModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>${isEdit ? 'Edit Package' : 'Add Package'}</h2>
                    <button class="modal-close" onclick="closePackageModal()">&times;</button>
                </div>
                <form id="packageForm" class="modal-body">
                    <input type="hidden" id="packageId" value="${isEdit ? pkg.id : ''}">
                    <div class="form-group">
                        <label>Package Name *</label>
                        <input type="text" id="packageName" placeholder="e.g. Monthly, 3-Month, Annual" value="${isEdit ? packageEscHtml(pkg.name) : ''}" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Duration (months) *</label>
                            <input type="number" id="packageDuration" min="1" step="1" value="${isEdit ? (parseInt(pkg.duration_months) || 1) : 1}" required>
                        </div>
                        <div class="form-group">
                            <label>Price *</label>
                            <input type="number" id="packagePrice" min="0" step="0.01" value="${isEdit ? (parseFloat(pkg.price) || 0) : ''}" placeholder="0.00" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Admission Fee (one-time)</label>
                            <input type="number" id="packageAdmission" min="0" step="0.01" value="${isEdit ? (parseFloat(pkg.admission_fee) || 0) : 0}" placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label>Status</label>
                            <select id="packageActive">
                                <option value="1" ${active ? 'selected' : ''}>Active</option>
                                <option value="0" ${active ? '' : 'selected'}>Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea id="packageDescription" rows="3" placeholder="What's included (optional)">${isEdit ? packageEscHtml(pkg.description || '') : ''}</textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closePackageModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">${isEdit ? 'Save Changes' : 'Add Package'}</button>
                    </div>
                </form>
            </div>
        </div>
    `;
    document.body.insertAdjacentHTML('beforeend', html);
    document.getElementById('packageForm').addEventListener('submit', function (e) {
        e.preventDefault();
        savePackage();
    });
}

function closePackageModal() {
    const m = document.getElementById('packageModal');
    if (m) m.remove();
}

function savePackage() {
    const id = document.getElementById('packageId').value;
    const isEdit = !!id;
    const name = document.getElementById('packageName').value.trim();
    if (!name) { Utils.showNotification('Package name is required.', 'error'); return; }
    const payload = {
        name: name,
        duration_months: parseInt(document.getElementById('packageDuration').value) || 1,
        price: parseFloat(document.getElementById('packagePrice').value) || 0,
        admission_fee: parseFloat(document.getElementById('packageAdmission').value) || 0,
        description: document.getElementById('packageDescription').value.trim() || null,
        is_active: parseInt(document.getElementById('packageActive').value)
    };
    if (isEdit) payload.id = id;

    fetch(`api/packages.php?action=${isEdit ? 'update' : 'create'}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
        .then(async res => {
            if (!res.ok) throw new Error('Failed to save package');
            return JSON.parse(await res.text());
        })
        .then(data => {
            if (data.success) {
                Utils.showNotification(data.message || (isEdit ? 'Package updated.' : 'Package added.'), 'success');
                closePackageModal();
                loadPackagesTable();
            } else {
                Utils.showNotification(data.message || 'Failed to save package', 'error');
            }
        })
        .catch(err => {
            console.error('Package save error:', err);
            Utils.showNotification(err.message || 'Error saving package', 'error');
        });
}

function deletePackage(id) {
    if (!requireAdminAccess('delete packages')) return;
    if (!confirm('Delete this package? This cannot be undone.')) return;
    fetch('api/packages.php?action=delete&id=' + encodeURIComponent(id), { method: 'POST' })
        .then(async res => JSON.parse(await res.text()))
        .then(data => {
            if (data && data.success) {
                Utils.showNotification('Package deleted.', 'success');
                loadPackagesTable();
            } else {
                Utils.showNotification(data?.message || 'Failed to delete package', 'error');
            }
        })
        .catch(err => {
            console.error('Package delete error:', err);
            Utils.showNotification('Error deleting package', 'error');
        });
}

/* ===================== Details (gym info + socials + packages) ===================== */
function loadDetails() {
    const contentBody = document.getElementById('contentBody');
    if (!contentBody) return;
    contentBody.innerHTML = `
        <div id="detailsSettingsContainer"><div class="loading">Loading gym details…</div></div>
        <div class="section-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;margin:2rem 0 1rem;">
            <div>
                <h2 style="margin:0;">Membership Packages</h2>
                <p style="margin:.25rem 0 0;color:var(--text-secondary);">The plans your gym sells — monthly and any others.</p>
            </div>
            ${isAdminUser() ? '<button class="btn btn-primary" onclick="showAddPackageForm()">+ Add Package</button>' : ''}
        </div>
        <div id="packagesTableContainer"><div class="loading">Loading packages…</div></div>
    `;
    loadDetailsSettings();
    loadPackagesTable();
}

function loadDetailsSettings() {
    fetch('api/settings.php?action=admin_get')
        .then(async res => {
            if (!res.ok) throw new Error('Failed to load details');
            return JSON.parse(await res.text());
        })
        .then(data => {
            if (data.success) renderDetailsSettings(data.data || {});
            else Utils.showNotification(data.message || 'Failed to load gym details', 'error');
        })
        .catch(err => {
            console.error('Details load error:', err);
            const c = document.getElementById('detailsSettingsContainer');
            if (c) c.innerHTML = '<div class="error">Could not load gym details.</div>';
        });
}

function renderDetailsSettings(s) {
    const c = document.getElementById('detailsSettingsContainer');
    if (!c) return;
    const admin = isAdminUser();
    const dis = admin ? '' : 'disabled';
    const field = (id, label, val, ph, type) => `
        <div class="form-group">
            <label>${label}</label>
            <input type="${type || 'text'}" id="${id}" value="${packageEscHtml(val || '')}" placeholder="${ph || ''}" ${dis}>
        </div>`;
    c.innerHTML = `
      <div class="dashboard-recent" style="padding:1.25rem;">
        <h2 style="margin:0 0 .25rem;">Gym Details &amp; Social Links</h2>
        <p style="color:var(--text-secondary);margin:0 0 1rem;">${admin ? 'These appear in your gym’s public footer. Only admin can change them.' : 'Only admin can change these.'}</p>
        <div class="form-row">
            ${field('set_gym_name', 'Gym Name', s.gym_name, 'Bhatti Gym')}
            ${field('set_phone', 'Phone', s.phone, '0300 1234567', 'tel')}
        </div>
        <div class="form-row">
            ${field('set_email', 'Email', s.email, 'gym@example.com', 'email')}
            ${field('set_address_url', 'Google Maps Link', s.address_url, 'https://maps.app.goo.gl/…', 'url')}
        </div>
        <div class="form-row">
            ${field('set_social_whatsapp', 'WhatsApp Link', s.social_whatsapp, 'https://whatsapp.com/channel/…', 'url')}
            ${field('set_social_youtube', 'YouTube Link', s.social_youtube, 'https://youtube.com/@…', 'url')}
        </div>
        <div class="form-row">
            ${field('set_social_facebook', 'Facebook Link', s.social_facebook, 'https://facebook.com/…', 'url')}
            ${field('set_social_instagram', 'Instagram Link', s.social_instagram, 'https://instagram.com/…', 'url')}
        </div>
        <div class="form-row">
            ${field('set_social_snapchat', 'Snapchat Link', s.social_snapchat, 'https://snapchat.com/add/…', 'url')}
            ${field('set_social_tiktok', 'TikTok Link', s.social_tiktok, 'https://tiktok.com/@…', 'url')}
        </div>
        ${admin ? '<button class="btn btn-primary" onclick="saveDetailsSettings()">Save Details</button>' : ''}
      </div>`;
}

function saveDetailsSettings() {
    if (!requireAdminAccess('change gym details')) return;
    const val = id => { const e = document.getElementById(id); return e ? e.value.trim() : ''; };
    const payload = {
        gym_name: val('set_gym_name'),
        phone: val('set_phone'),
        email: val('set_email'),
        address_url: val('set_address_url'),
        social_whatsapp: val('set_social_whatsapp'),
        social_youtube: val('set_social_youtube'),
        social_facebook: val('set_social_facebook'),
        social_instagram: val('set_social_instagram'),
        social_snapchat: val('set_social_snapchat'),
        social_tiktok: val('set_social_tiktok')
    };
    fetch('api/settings.php?action=save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
        .then(async res => {
            if (!res.ok) throw new Error('Failed to save details');
            return JSON.parse(await res.text());
        })
        .then(data => {
            Utils.showNotification(data.success ? (data.message || 'Details saved.') : (data.message || 'Failed to save details'), data.success ? 'success' : 'error');
        })
        .catch(err => {
            console.error('Details save error:', err);
            Utils.showNotification('Error saving details', 'error');
        });
}

// ============================================================================
// MEMBER REGISTRATIONS — the public "Create profile" queue. Admin reviews a
// request, then approves (creates the member + records the first payment +
// assigns the member code) or rejects. All applicant text is escaped because
// it comes from public input. Admin only (staff is blocked from the section).
// ============================================================================
let _registrationsCache = [];

function loadRegistrations() {
    const html = `
        <div class="members-section">
            ${renderSectionGuideCard({
                chip: 'Registrations Help',
                title: 'New member requests',
                description: 'People who tap "Create profile" on the login page land here. Approve after payment to create the member and give them a code.',
                steps: [
                    'Review the request (Approve opens the full details).',
                    'Enter the fees and the amount paid, then confirm the member code.',
                    'Approving creates the member, records the first payment, and activates them.'
                ]
            })}
            <div class="section-header">
                <div class="section-actions">
                    <select id="regStatusFilter" class="search-input" style="max-width:170px;">
                        <option value="pending">Pending</option>
                        <option value="approved">Approved</option>
                        <option value="rejected">Rejected</option>
                        <option value="all">All</option>
                    </select>
                    <input type="text" id="regSearch" placeholder="Search by name, phone, CNIC, or code" class="search-input">
                </div>
            </div>
            <div id="registrationsTableContainer"></div>
        </div>
    `;
    document.getElementById('contentBody').innerHTML = html;
    document.getElementById('regStatusFilter')?.addEventListener('change', () => loadRegistrationsTable(1));
    document.getElementById('regSearch')?.addEventListener('input', Utils.debounce(() => loadRegistrationsTable(1), 300));
    loadRegistrationsTable(1);
}

function loadRegistrationsTable(page = 1) {
    const status = document.getElementById('regStatusFilter')?.value || 'pending';
    const search = document.getElementById('regSearch')?.value || '';
    const container = document.getElementById('registrationsTableContainer');
    if (container) container.innerHTML = '<div class="loading">Loading...</div>';
    fetch(`api/registrations.php?action=list&status=${encodeURIComponent(status)}&page=${page}&search=${encodeURIComponent(search)}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) throw new Error(data.message || 'Failed to load registrations');
            _registrationsCache = data.data || [];
            const rows = _registrationsCache;
            const pagination = data.pagination || { page: 1, pages: 1, limit: 20 };
            const startIndex = ((pagination.page || 1) - 1) * (pagination.limit || 20);
            const badge = (s) => s === 'pending' ? '<span class="status-badge status-pending">Pending</span>'
                : s === 'approved' ? '<span class="status-badge status-active">Approved</span>'
                    : '<span class="status-badge status-inactive">Rejected</span>';
            container.innerHTML = `
                <table class="data-table">
                    <thead><tr>
                        <th>#</th><th>Name</th><th>Phone</th><th>Side</th><th>CNIC</th><th>Requested</th><th>Status</th><th>Actions</th>
                    </tr></thead>
                    <tbody>
                        ${rows.length ? rows.map((r, idx) => `
                            <tr>
                                <td data-label="#">${startIndex + idx + 1}</td>
                                <td data-label="Name">${escapeHtml(r.name || '-')}</td>
                                <td data-label="Phone">${escapeHtml(r.phone || '-')}</td>
                                <td data-label="Side">${r.gender === 'women' ? 'Women' : 'Men'}</td>
                                <td data-label="CNIC">${escapeHtml(r.cnic || '-')}</td>
                                <td data-label="Requested">${Utils.formatDate(r.created_at)}</td>
                                <td data-label="Status">${badge(r.status)}${r.assigned_member_code ? ' <strong>' + escapeHtml(r.assigned_member_code) + '</strong>' : ''}</td>
                                <td data-label="Actions">
                                    ${r.status === 'pending' ? `
                                        <button class="btn btn-sm btn-primary" onclick="showApproveRegistration(${r.id})">Approve</button>
                                        <button class="btn btn-sm btn-danger" onclick="rejectRegistration(${r.id})">Reject</button>
                                    ` : `<button class="btn btn-sm btn-secondary" onclick="showRegistrationDetails(${r.id})">View</button>`}
                                </td>
                            </tr>
                        `).join('') : '<tr><td colspan="8"><div class="empty-state"><strong>No requests</strong>New "Create profile" requests will show up here.</div></td></tr>'}
                    </tbody>
                </table>
                ${pagination.pages > 1 ? `
                    <div class="pagination" style="margin-top:1rem;display:flex;gap:1rem;justify-content:center;align-items:center;">
                        <button class="btn btn-secondary" ${pagination.page === 1 ? 'disabled' : ''} onclick="loadRegistrationsTable(${pagination.page - 1})">Previous</button>
                        <span>Page ${pagination.page} of ${pagination.pages}</span>
                        <button class="btn btn-secondary" ${pagination.page === pagination.pages ? 'disabled' : ''} onclick="loadRegistrationsTable(${pagination.page + 1})">Next</button>
                    </div>` : ''}
            `;
        })
        .catch(err => { if (container) container.innerHTML = `<div class="error">${escapeHtml(err.message)}</div>`; });
}

function _regById(id) { return _registrationsCache.find(r => String(r.id) === String(id)); }

function showRegistrationDetails(id) {
    const r = _regById(id);
    if (!r) return;
    let d = {};
    if (r.details) { try { d = JSON.parse(r.details) || {}; } catch (e) { d = {}; } }
    const row = (label, val) => (val ? `<div class="detail-item" style="display:flex;justify-content:space-between;gap:1rem;padding:.5rem 0;border-bottom:1px solid var(--border-color);"><span class="detail-label">${label}</span><strong style="text-align:right;">${escapeHtml(String(val))}</strong></div>` : '');
    const ml = { height: 'Height', weight: 'Weight', chest: 'Chest', waist: 'Waist', shoulder: 'Shoulder', bicep: 'Bicep', forearm: 'Forearm', hip: 'Hip', thigh: 'Thigh', calf: 'Calf' };
    const measures = Object.keys(ml).filter(k => d[k]).map(k => ml[k] + ': ' + d[k]).join(' · ');
    const html = `
        <div class="modal" id="regDetailModal">
            <div class="modal-content">
                <div class="modal-header"><h2>Request details</h2><button class="modal-close" onclick="document.getElementById('regDetailModal').remove()">&times;</button></div>
                <div class="modal-body">
                    ${row('Name', r.name)}${row('Phone (Cell)', r.phone)}${row('CNIC', r.cnic)}
                    ${row('Side', r.gender === 'women' ? 'Women' : 'Men')}
                    ${row("Husband's / Father's name", d.father_name)}${row('Occupation', d.occupation)}
                    ${row('Date of birth', r.dob)}${row('Email', d.email)}
                    ${row('Residence address', r.address)}${row('Office address', d.office_address)}${row('Office phone', d.office_phone)}
                    ${row('Blood group', d.blood_group)}${row('Measurements', measures)}
                    ${row('Status', r.status)}${r.assigned_member_code ? row('Member code', r.assigned_member_code) : ''}
                    ${r.rejection_reason ? row('Reason', r.rejection_reason) : ''}
                </div>
                <div class="modal-footer"><button class="btn btn-secondary" onclick="document.getElementById('regDetailModal').remove()">Close</button></div>
            </div>
        </div>`;
    document.body.insertAdjacentHTML('beforeend', html);
}

function showApproveRegistration(id) {
    const r = _regById(id);
    if (!r) return;
    const today = new Date().toISOString().slice(0, 10);
    const nm = new Date(); nm.setMonth(nm.getMonth() + 1);
    const nextDue = nm.toISOString().slice(0, 10);
    const html = `
        <div class="modal" id="approveRegModal">
            <div class="modal-content">
                <div class="modal-header"><h2>Approve &amp; create member</h2><button class="modal-close" onclick="closeApproveRegModal()">&times;</button></div>
                <form id="approveRegForm" class="modal-body">
                    <input type="hidden" id="ar_id" value="${r.id}">
                    <div class="section-guide" style="margin-bottom:1rem;padding:.75rem 1rem;">
                        <strong>${escapeHtml(r.name || '')}</strong> · ${escapeHtml(r.phone || '')} · ${r.gender === 'women' ? 'Women' : 'Men'}
                        ${r.cnic ? '<br>CNIC: ' + escapeHtml(r.cnic) : ''}${r.address ? '<br>' + escapeHtml(r.address) : ''}
                    </div>
                    <div class="form-group"><label>Side (which section)</label><select id="ar_side"><option value="men" ${r.gender !== 'women' ? 'selected' : ''}>Men</option><option value="women" ${r.gender === 'women' ? 'selected' : ''}>Women</option></select></div>
                    <div class="form-group"><label>Member code / serial *</label><input type="text" id="ar_code" required placeholder="Loading suggestion…"></div>
                    <div class="form-group"><label>Join date</label><input type="date" id="ar_join" value="${today}"></div>
                    <div class="form-group"><label>Admission fee</label><input type="number" id="ar_admission" min="0" step="any" value="0"></div>
                    <div class="form-group"><label>Monthly fee</label><input type="number" id="ar_monthly" min="0" step="any" value="0"></div>
                    <div class="form-group"><label>Locker fee</label><input type="number" id="ar_locker" min="0" step="any" value="0"></div>
                    <div class="form-group"><label>Personal training fee (PTF)</label><input type="number" id="ar_ptf" min="0" step="any" value="0"></div>
                    <div class="form-group"><label>Amount paid now</label><input type="number" id="ar_paid" min="0" step="any" value="0"></div>
                    <div class="form-group"><label>Payment method</label><select id="ar_method"><option>Cash</option><option>Card</option><option>Bank Transfer</option><option>Easypaisa</option><option>JazzCash</option></select></div>
                    <div class="form-group"><label>Next fee due date</label><input type="date" id="ar_nextdue" value="${nextDue}"></div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeApproveRegModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Approve &amp; create</button>
                    </div>
                </form>
            </div>
        </div>`;
    document.body.insertAdjacentHTML('beforeend', html);
    fetch('api/registrations.php?action=next_code').then(res => res.json()).then(d => {
        const el = document.getElementById('ar_code');
        if (el && d && d.success) { el.value = d.next_code; el.placeholder = ''; }
    }).catch(() => { const el = document.getElementById('ar_code'); if (el) el.placeholder = 'Enter a code'; });
    document.getElementById('approveRegForm')?.addEventListener('submit', function (e) { e.preventDefault(); saveApproveRegistration(); });
}

function closeApproveRegModal() { document.getElementById('approveRegModal')?.remove(); }

function saveApproveRegistration() {
    const payload = {
        id: document.getElementById('ar_id')?.value,
        gender: document.getElementById('ar_side')?.value || 'men',
        member_code: document.getElementById('ar_code')?.value?.trim(),
        join_date: document.getElementById('ar_join')?.value,
        admission_fee: document.getElementById('ar_admission')?.value || 0,
        monthly_fee: document.getElementById('ar_monthly')?.value || 0,
        locker_fee: document.getElementById('ar_locker')?.value || 0,
        ptf_fee: document.getElementById('ar_ptf')?.value || 0,
        amount_paid: document.getElementById('ar_paid')?.value || 0,
        payment_method: document.getElementById('ar_method')?.value || 'Cash',
        next_fee_due_date: document.getElementById('ar_nextdue')?.value
    };
    if (!payload.member_code) { Utils.showNotification('Enter a member code', 'error'); return; }
    fetch('api/registrations.php?action=approve', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
        .then(res => res.json())
        .then(data => {
            if (!data.success) throw new Error(data.message || 'Failed to approve');
            Utils.showNotification(data.message || 'Member created', 'success');
            closeApproveRegModal();
            loadRegistrationsTable(1);
        })
        .catch(err => Utils.showNotification(err.message, 'error'));
}

function rejectRegistration(id) {
    if (!confirm('Reject this request? It will not create a member.')) return;
    fetch('api/registrations.php?action=reject', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id }) })
        .then(res => res.json())
        .then(data => {
            if (!data.success) throw new Error(data.message || 'Failed to reject');
            Utils.showNotification(data.message || 'Rejected', 'success');
            loadRegistrationsTable(1);
        })
        .catch(err => Utils.showNotification(err.message, 'error'));
}
