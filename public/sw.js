// Dot.Ehail service worker (ds, R-46). It caches the app shell only: built CSS and JS, self-hosted fonts, icons and
// the offline page. It never caches a page, an API response or anything carrying personal data: navigations always
// go to the network and fall back to /offline.html only when the network is unreachable.
const VERSION = 'eh-shell-v1';
const PRECACHE = [
    '/offline.html',
    '/manifest.webmanifest',
    '/icons/icon-192.png',
    '/fonts/barlow-latin-400-normal.woff2',
    '/fonts/barlow-latin-600-normal.woff2',
    '/fonts/barlow-latin-700-normal.woff2',
    '/fonts/barlow-condensed-latin-700-normal.woff2',
];
const SHELL_PATHS = [/^\/build\/assets\/[\w.-]+\.(css|js|woff2)$/, /^\/fonts\/[\w.-]+\.woff2$/, /^\/icons\/[\w.-]+\.png$/];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(VERSION).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key.startsWith('eh-shell-') && key !== VERSION).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match('/offline.html')));
        return;
    }

    if (SHELL_PATHS.some((pattern) => pattern.test(url.pathname))) {
        event.respondWith(
            caches.match(request).then((cached) => cached || fetch(request).then((response) => {
                if (response.ok && response.type === 'basic') {
                    const copy = response.clone();
                    caches.open(VERSION).then((cache) => cache.put(request, copy));
                }
                return response;
            })),
        );
    }
    // Everything else (pages, JSON, uploads, the map) goes straight to the network, uncached.
});
