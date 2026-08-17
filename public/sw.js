// Solyx RPG service worker — makes the site installable and speeds up repeat loads
// of static assets. Deliberately does NOT cache pages or /api/* responses: this is a
// live, server-authoritative game, so a stale cached page or API response would be
// actively wrong rather than just slow. Bump CACHE_NAME to invalidate old caches.
const CACHE_NAME = 'solyx-static-v1';

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(
            keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))
        )).then(() => self.clients.claim())
    );
});

function isImmutableAsset(url) {
    return url.origin === self.location.origin
        && (url.pathname.startsWith('/build/') || url.pathname.startsWith('/images/'));
}

self.addEventListener('fetch', (event) => {
    if (event.request.method !== 'GET') {
        return;
    }

    const url = new URL(event.request.url);
    if (!isImmutableAsset(url)) {
        return; // let the browser handle pages/API calls normally — no caching, no offline fallback.
    }

    event.respondWith(
        caches.open(CACHE_NAME).then(async (cache) => {
            const cached = await cache.match(event.request);
            if (cached) {
                return cached;
            }
            const response = await fetch(event.request);
            if (response.ok) {
                cache.put(event.request, response.clone());
            }
            return response;
        })
    );
});
