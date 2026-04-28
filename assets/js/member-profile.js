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
        lookupInput.addEventListener('keypress', function (e) {
            if (e.key === 'Enter') {
                handleLookup();
            }
        });
    }

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

function handleLookup() {
    const memberCode = document.getElementById('memberCodeInput').value.trim();

    if (!memberCode) {
        Utils.showNotification('Please enter member code.', 'error');
        return;
    }

    // Use member-profile.php which checks both genders by exact member code.
    // This is the same endpoint used to load the profile, so we get member data and check in.
    fetch(`api/member-profile.php?code=${encodeURIComponent(memberCode)}`)
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
            if (!data || !data.success || !data.profile) {
                Utils.showNotification(data?.message || 'Member not found', 'error');
                return;
            }

            const member = data.profile;
            const memberId = member.id;
            const memberGender = data.gender;

            console.log('Member found:', { memberId, memberGender, memberCode: member.member_code });

            if (!memberId || !memberGender) {
                console.error('Invalid member data:', { memberId, memberGender });
                Utils.showNotification('Invalid member data', 'error');
                return;
            }

            // Check in attendance FIRST (same as admin check-in)
            console.log('Attempting check-in:', { memberId, gender: memberGender });
            fetch('api/attendance-checkin.php?action=checkin', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    member_id: memberId,
                    gender: memberGender
                })
            })
                .then(res => {
                    if (!res.ok) {
                        return res.text().then(text => {
                            try {
                                return JSON.parse(text);
                            } catch {
                                throw new Error('Network error: ' + text.substring(0, 100));
                            }
                        });
                    }
                    return res.json();
                })
                .then(result => {
                    console.log('Check-in response:', result);
                    if (result.success) {
                        Utils.showNotification('Member checked in successfully.', 'success');
                        // Now load the profile after successful check-in
                        loadMemberProfile(memberCode);
                    } else {
                        console.warn('Check-in failed:', result.message);
                        // Even if check-in fails (e.g., already checked in), still load the profile
                        Utils.showNotification(result.message || 'Profile opened, but check-in status is unclear.', 'info');
                        loadMemberProfile(memberCode);
                    }
                })
                .catch(error => {
                    console.error('Check-in error:', error);
                    // Even if check-in fails, still load the profile
                    Utils.showNotification('Check-in had an issue. Opening profile anyway.', 'error');
                    loadMemberProfile(memberCode);
                });
        })
        .catch(error => {
            console.error('Member lookup error:', error);
            Utils.showNotification('Could not find the member: ' + error.message, 'error');
        });
}

function loadMemberProfile(searchTerm) {
    const contentDiv = document.getElementById('memberContent');
    contentDiv.innerHTML = '<div class="loading">Opening member profile...</div>';

    // Load profile directly by exact member code
    fetch(`api/member-profile.php?code=${encodeURIComponent(searchTerm)}`)
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
            if (data && data.success) {
                renderMemberProfile(data);
                // Synchronous load complete.
            } else {
                contentDiv.innerHTML = `<div class="error">${data?.message || 'Member not found'}</div>`;
                Utils.showNotification(data?.message || 'Member not found.', 'error');
            }
        })
        .catch(err => {
            console.error('Profile error:', err);
            contentDiv.innerHTML = `<div class="error">Could not open member profile: ${err.message}</div>`;
            Utils.showNotification('Could not open member profile.', 'error');
        });
}

function loadMemberPayments(memberId) {
    const historyContainer = document.getElementById('feeHistoryContainer');
    if (!historyContainer) return;

    historyContainer.innerHTML = '<div class="loading-small">Loading payment history...</div>';

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
                const calendarHTML = renderAttendanceCalendar(year, month, data.calendar, currentMemberData.next_fee_due_date);
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

function logoutMemberProfile() {
    fetch('api/auth.php?action=logout', {
        method: 'POST',
        keepalive: true
    })
        .catch(() => null)
        .finally(() => {
            window.location.replace('index.html');
        });
}

function renderMemberProfile(data) {
    const member = data.profile || data.data;
    const gender = data.gender || window.MEMBER_GENDER;
    const isDefaulter = data.is_defaulter || false;
    const defaultDate = data.default_date || member.next_fee_due_date || null;

    // Store member data for attendance check-in
    currentMemberData = {
        id: member.id,
        code: member.member_code,
        gender: gender,
        isDefaulter: isDefaulter,
        status: member.status
    };

    // Store payments for pagination
    allMemberPayments = data.payments || [];
    currentPaymentPage = 1; // Reset to first page

    // Load attendance calendar
    const year = data.attendance?.year || new Date().getFullYear();
    const month = data.attendance?.month || new Date().getMonth() + 1;
    const attendanceCalendar = data.attendance?.calendar || {};

    const profileCardClass = isDefaulter ? 'profile-card defaulter' : 'profile-card';

    const html = `
        <div class="member-profile">
            <div class="profile-layout" style="display: grid; grid-template-columns: 450px 1fr; gap: 2rem; align-items: start;">
                <!-- Left Side: Profile Info -->
                <div class="profile-sidebar">
                    <div class="${profileCardClass}" id="profileCard">
                        <div class="profile-image">
                            ${member.profile_image ?
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
                            <div class="detail-item">
                                <span class="detail-label">Phone:</span>
                                <span class="detail-value">${member.phone}</span>
                            </div>
                            ${member.email ? `
                            <div class="detail-item">
                                <span class="detail-label">Email:</span>
                                <span class="detail-value">${member.email}</span>
                            </div>
                            ` : ''}
                            ${member.address ? `
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
                            <div class="detail-item">
                                <span class="detail-label">Status:</span>
                                <span class="status-badge status-${member.status}">${member.status}</span>
                            </div>
                            ${member.nfc_uid ? `
                            <div class="detail-item" style="background: rgba(67, 105, 255, 0.1); padding: 0.75rem; border-radius: 5px; margin-top: 1rem; border: 1px solid rgba(67, 105, 255, 0.3);">
                                <span class="detail-label" style="color: #4f46e5; font-weight: bold;">📱 RFID Card UID:</span>
                                <span class="detail-value" style="color: #4f46e5; font-family: monospace; font-size: 0.9rem; word-break: break-all;">${member.nfc_uid}</span>
                            </div>
                            ` : `
                            <div class="detail-item" style="background: rgba(156, 163, 175, 0.1); padding: 0.75rem; border-radius: 5px; margin-top: 1rem; border: 1px solid rgba(156, 163, 175, 0.3);">
                                <span class="detail-label" style="color: #6b7280;">📱 RFID Card UID:</span>
                                <span class="detail-value" style="color: #6b7280; font-style: italic;">Not assigned</span>
                            </div>
                            `}
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
                            ${renderFeeHistory(allMemberPayments)}
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Bottom: Attendance This Month -->
            <div class="calendar-wrapper" style="margin-top: 2rem; padding: 0 2rem; display: flex; justify-content: center;">
                <div class="calendar-section" style="width: 100%; max-width: 600px;">
                    <h2>Attendance This Month</h2>
                    <div class="attendance-calendar" id="attendanceCalendar">
                        ${renderAttendanceCalendar(year, month, attendanceCalendar, defaultDate)}
                    </div>
                    <div style="margin-top: 1rem; padding: 1rem; background: rgba(67, 105, 255, 0.08); border-radius: 8px; border: 1px solid rgba(67, 105, 255, 0.4); display: flex; align-items: center; justify-content: space-between;">
                        <div>
                            <p style="margin: 0 0 0.5rem 0; color: #8b5cf6; font-weight: bold;">Attendance</p>
                            <p style="margin: 0; color: var(--text-secondary);">
                                Attendance is usually marked automatically when you open this profile.
                            </p>
                        </div>
                        <button onclick="checkInAttendance()" style="padding: 0.75rem 2rem; background: #4f46e5; color: white; border: none; border-radius: 5px; cursor: pointer; font-weight: bold; transition: all 0.3s; white-space: nowrap;">Mark Check-In</button>
                    </div>
                </div>
            </div>
        </div>
    `;

    document.getElementById('memberContent').innerHTML = html;
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
    fetch('api/attendance-checkin.php?action=checkin', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            member_id: currentMemberData.id,
            gender: currentMemberData.gender
        })
    })
        .then(res => {
            if (!res.ok) {
                return res.text().then(text => {
                    try {
                        return JSON.parse(text);
                    } catch {
                        throw new Error('Network error: ' + text.substring(0, 100));
                    }
                });
            }
            return res.json();
        })
        .then(data => {
            if (data.success) {
                Utils.showNotification('Member checked in successfully.', 'success');

                // Reload profile to update calendar
                setTimeout(() => {
                    loadMemberProfile(currentMemberData.code);
                }, 600);
            } else {
                Utils.showNotification(data.message || 'Failed to record check-in', 'error');
            }
        })
        .catch(err => {
            console.error('Check-in error:', err);
            Utils.showNotification('Failed to record check-in: ' + err.message, 'error');
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
