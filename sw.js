const SHELL_CACHE = 'gym-shell-v2';
const DATA_CACHE = 'gym-data-v1';
const SHELL_ASSETS = [
    '/',
    '/index.html',
    '/admin-dashboard.html',
    '/member-profile-men.html',
    '/member-profile-women.html',
    '/manifest.webmanifest',
    '/assets/css/style.css?v=7',
    '/assets/css/admin-dashboard.css?v=7',
    '/assets/css/member-profile.css?v=7',
    '/assets/css/pwa.css',
    '/assets/js/utils.js?v=6',
    '/assets/js/auth.js?v=6',
    '/assets/js/admin-dashboard.js?v=8',
    '/assets/js/member-profile.js?v=6',
    '/assets/js/pwa.js',
    '/assets/icons/gym-icon.svg',
    '/assets/icons/gym-icon-192.png',
    '/assets/icons/gym-icon-512.png'
];

self.addEventListener('install', event => {
    event.waitUntil((async () => {
        const cache = await caches.open(SHELL_CACHE);
        await cache.addAll(SHELL_ASSETS);
        self.skipWaiting();
    })());
});

self.addEventListener('activate', event => {
    event.waitUntil((async () => {
        const keys = await caches.keys();
        await Promise.all(keys.map(key => {
            if (key !== SHELL_CACHE && key !== DATA_CACHE) {
                return caches.delete(key);
            }
            return Promise.resolve(false);
        }));
        await self.clients.claim();
    })());
});

self.addEventListener('fetch', event => {
    const request = event.request;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    if (url.pathname === '/api/auth.php') {
        return;
    }

    if (url.pathname === '/api/dashboard.php') {
        event.respondWith(handleDashboardRequest(request));
        return;
    }

    if (url.pathname === '/api/member-profile.php') {
        event.respondWith(handleMemberProfileRequest(request));
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(handleNavigationRequest(request));
        return;
    }

    if (isShellAsset(url.pathname)) {
        event.respondWith(cacheFirst(request));
    }
});

function isShellAsset(pathname) {
    return pathname.startsWith('/assets/') || pathname === '/manifest.webmanifest';
}

async function handleNavigationRequest(request) {
    const cache = await caches.open(SHELL_CACHE);
    const url = new URL(request.url);
    const fallbackPath = getNavigationFallbackPath(url.pathname);
    try {
        const response = await fetch(request);
        if (response && response.ok) {
            cache.put(fallbackPath, response.clone());
        }
        return response;
    } catch (error) {
        const cached = await cache.match(fallbackPath) || await cache.match('/index.html');
        if (cached) return cached;
        throw error;
    }
}

function getNavigationFallbackPath(pathname) {
    if (pathname === '/' || pathname === '') return '/index.html';
    return pathname;
}

async function cacheFirst(request) {
    const cache = await caches.open(SHELL_CACHE);
    const cached = await cache.match(request);
    if (cached) return cached;

    const response = await fetch(request);
    if (response && response.ok) {
        cache.put(request, response.clone());
    }
    return response;
}

async function handleMemberProfileRequest(request) {
    try {
        return await fetch(request);
    } catch (error) {
        return new Response(JSON.stringify({
            success: false,
            message: 'Member profile lookup is temporarily unavailable. Please check the connection and try again.'
        }), {
            status: 503,
            headers: {
                'Content-Type': 'application/json; charset=utf-8',
                'Cache-Control': 'no-store'
            }
        });
    }
}

async function handleDashboardRequest(request) {
    const normalizedRequest = normalizeDashboardRequest(request);
    const cache = await caches.open(DATA_CACHE);
    try {
        const networkResponse = await fetch(request);
        if (networkResponse && networkResponse.ok) {
            const sanitized = await sanitizeDashboardResponse(networkResponse.clone());
            await cache.put(normalizedRequest, sanitized.clone());
            return sanitized;
        }
        return networkResponse;
    } catch (error) {
        const cached = await cache.match(normalizedRequest);
        if (cached) return cached;
        throw error;
    }
}

function normalizeDashboardRequest(request) {
    const url = new URL(request.url);
    url.searchParams.delete('_');
    return new Request(url.toString(), {
        method: 'GET',
        credentials: request.credentials,
        headers: request.headers
    });
}

async function sanitizeDashboardResponse(response) {
    const fallback = response.clone();
    try {
        const payload = await response.json();
        const data = payload && payload.data ? JSON.parse(JSON.stringify(payload.data)) : null;
        if (!payload || !data) return fallback;

        if (data.men && Array.isArray(data.men.recent)) data.men.recent = [];
        if (data.women && Array.isArray(data.women.recent)) data.women.recent = [];

        return new Response(JSON.stringify({
            success: true,
            data
        }), {
            status: 200,
            headers: {
                'Content-Type': 'application/json; charset=utf-8',
                'Cache-Control': 'no-store'
            }
        });
    } catch (error) {
        return fallback;
    }
}
