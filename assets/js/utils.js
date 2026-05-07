/**
 * Utility Functions
 */

const Utils = {
    // Show notification
    showNotification: function(message, type = 'info') {
        // Remove existing notifications
        const existing = document.querySelector('.notification');
        if (existing) {
            existing.remove();
        }

        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.textContent = message;

        document.body.appendChild(notification);

        // Trigger animation
        setTimeout(() => {
            notification.classList.add('show');
        }, 10);

        // Auto remove after 5 seconds
        setTimeout(() => {
            notification.classList.remove('show');
            setTimeout(() => notification.remove(), 300);
        }, 5000);
    },

    // Format date
    formatDate: function(dateString) {
        if (!dateString) return 'N/A';
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    },

    // Format currency (Pakistani Rupees)
    formatCurrency: function(amount) {
        return new Intl.NumberFormat('en-PK', {
            style: 'currency',
            currency: 'PKR',
            minimumFractionDigits: 0,
            maximumFractionDigits: 2
        }).format(amount);
    },

    // Debounce function
    debounce: function(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const context = this;
            const later = () => {
                clearTimeout(timeout);
                func.apply(context, args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    },

    // Validate email
    validateEmail: function(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    },

    // Validate phone (Pakistani format)
    validatePhone: function(phone) {
        // Remove all non-digit characters
        const digits = phone.replace(/\D/g, '');
        // Pakistani numbers: 11 digits (03XX-XXXXXXX) or 10 digits (3XX-XXXXXXX)
        return digits.length >= 10 && digits.length <= 11;
    },

    // Sanitize input to prevent XSS
    sanitizeInput: function(input) {
        if (typeof input !== 'string') return input;
        const div = document.createElement('div');
        div.textContent = input;
        return div.innerHTML;
    },

    // Format phone number (Pakistani format)
    formatPhone: function(phone) {
        const digits = phone.replace(/\D/g, '');
        if (digits.length === 11) {
            return `${digits.slice(0, 4)}-${digits.slice(4)}`;
        } else if (digits.length === 10) {
            return `${digits.slice(0, 3)}-${digits.slice(3)}`;
        }
        return phone;
    },

    // Show loading state on button
    setButtonLoading: function(button, loading) {
        if (loading) {
            button.disabled = true;
            button.dataset.originalText = button.innerHTML;
            button.innerHTML = '<span class="loading-spinner"></span> Loading...';
        } else {
            button.disabled = false;
            button.innerHTML = button.dataset.originalText || button.innerHTML;
        }
    },

    isOnline: function() {
        return navigator.onLine !== false;
    },

    renderOfflineNotice: function(target, title, message, actionLabel = 'Try again') {
        const container = typeof target === 'string' ? document.querySelector(target) || document.getElementById(target) : target;
        if (!container) return;

        container.innerHTML = `
            <div class="section-card" style="border-left: 4px solid #f59e0b; background: #fff7ed;">
                <div class="page-chip" style="background: #fef3c7; color: #92400e;">Offline</div>
                <h2 style="margin-top: 0.75rem;">${title}</h2>
                <p>${message}</p>
                <div style="margin-top: 1rem; display: flex; gap: 0.75rem; flex-wrap: wrap;">
                    <button class="btn btn-secondary" onclick="window.location.reload()">${actionLabel}</button>
                </div>
            </div>
        `;
    },

    clearSensitiveCaches: async function() {
        if (!('caches' in window)) return;
        const keys = await caches.keys();
        await Promise.all(keys.filter(key => key === 'gym-data-v1').map(key => caches.delete(key)));
    },

    // Authenticated POST helper
    apiPost: async function(url, data) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        }).then(res => res.json());
    }
};

