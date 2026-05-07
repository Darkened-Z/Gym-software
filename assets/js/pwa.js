(function () {
    const SW_PATH = '/sw.js';

    function getStatusConfig() {
        const online = navigator.onLine !== false;
        return online
            ? {
                className: 'is-online',
                title: 'Online',
                detail: 'Live data and sign-in are available.'
            }
            : {
                className: 'is-offline',
                title: 'Offline',
                detail: 'Shell is available. Live data and sign-in need a connection.'
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

    function updateStatusBar() {
        const bar = ensureStatusBar();
        const config = getStatusConfig();
        const titleEl = bar.querySelector('.pwa-status-bar__title');
        const detailEl = bar.querySelector('.pwa-status-bar__detail');
        const badgeEl = bar.querySelector('.pwa-status-bar__badge');

        bar.classList.remove('is-online', 'is-offline');
        bar.classList.add(config.className);
        titleEl.textContent = config.title;
        detailEl.textContent = config.detail;
        badgeEl.textContent = config.className === 'is-online' ? '✓' : '•';
        document.documentElement.dataset.networkStatus = config.className === 'is-online' ? 'online' : 'offline';
        document.body.classList.toggle('pwa-offline', config.className === 'is-offline');
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

    function init() {
        updateStatusBar();
        window.addEventListener('online', updateStatusBar);
        window.addEventListener('offline', updateStatusBar);
        registerServiceWorker();
    }

    window.GymPWA = window.GymPWA || {
        updateStatus: updateStatusBar,
        isOnline: function () {
            return navigator.onLine !== false;
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})();
