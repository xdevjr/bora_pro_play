const CACHE_NAME = 'bora-pro-play-v2';
const APP_SHELL = ['/', '/manifest.webmanifest', '/pwa-icon.svg', '/favicon.ico', '/favicon.svg', '/apple-touch-icon.png'];

function isHtmlRequest(request) {
    const accept = request.headers.get('Accept') ?? '';

    return accept.includes('text/html');
}

function isSpaPageRequest(request) {
    return request.headers.get('X-Requested-With') === 'XMLHttpRequest' && isHtmlRequest(request);
}

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => cache.addAll(APP_SHELL))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(
            keys
                .filter((key) => key !== CACHE_NAME)
                .map((key) => caches.delete(key)),
        )).then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    if (event.request.method !== 'GET') {
        return;
    }

    const requestUrl = new URL(event.request.url);

    if (requestUrl.origin !== self.location.origin) {
        return;
    }

    if (event.request.mode === 'navigate' || isSpaPageRequest(event.request)) {
        event.respondWith(networkFirst(event.request));

        return;
    }

    event.respondWith(staleWhileRevalidate(event.request));
});

async function networkFirst(request) {
    const cache = await caches.open(CACHE_NAME);

    try {
        const response = await fetch(request);
        cache.put(request, response.clone());

        return response;
    } catch {
        return (await cache.match(request)) || (await cache.match('/'));
    }
}

async function staleWhileRevalidate(request) {
    const cache = await caches.open(CACHE_NAME);
    const cachedResponse = await cache.match(request);

    const networkResponsePromise = fetch(request)
        .then((response) => {
            cache.put(request, response.clone());

            return response;
        })
        .catch(() => cachedResponse);

    return cachedResponse || networkResponsePromise;
}