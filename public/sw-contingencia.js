const CACHE_NAME = 'sin-internet-v1';
const CACHE_PREFIX = 'sin-internet-';
const OFFLINE_URL = '/sin-internet';

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        const response = await fetch(OFFLINE_URL, { cache: 'no-store', redirect: 'error' });
        if (response.status !== 200) {
            throw new Error('No se pudo guardar el aviso sin internet');
        }
        const cache = await caches.open(CACHE_NAME);
        await cache.put(OFFLINE_URL, response);
        await self.skipWaiting();
    })());
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const names = await caches.keys();
        await Promise.all(names.filter((name) => name.startsWith(CACHE_PREFIX) && name !== CACHE_NAME)
            .map((name) => caches.delete(name)));
        await self.clients.claim();
    })());
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.mode !== 'navigate' || request.method !== 'GET') return;

    event.respondWith((async () => {
        let response;
        try {
            response = await fetch(request);
            if (![502, 504, 520, 521, 522, 523, 524, 525, 526, 527].includes(response.status)) {
                return response;
            }
        } catch (error) {
            const offline = await caches.match(OFFLINE_URL, { cacheName: CACHE_NAME });
            if (offline) return offline;
            throw error;
        }
        return (await caches.match(OFFLINE_URL, { cacheName: CACHE_NAME })) || response;
    })());
});
