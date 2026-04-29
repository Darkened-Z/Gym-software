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

