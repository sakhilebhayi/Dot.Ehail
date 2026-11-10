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

// R-46 comms: Web Push (docs/R46_COMMS.md). The message arrives encrypted and is shown, never stored: nothing from a
// push is cached. Only a path on this site is opened on a tap; anything else opens the app's home.
self.addEventListener('push', (event) => {
    let data = {};
    try { data = event.data ? event.data.json() : {}; } catch { data = {}; }
    const title = typeof data.title === 'string' && data.title ? data.title : 'Dot.Ehail';
    const url = typeof data.url === 'string' && data.url.startsWith('/') && !data.url.startsWith('//') ? data.url : '/home';
    event.waitUntil(self.registration.showNotification(title, {
        body: typeof data.body === 'string' ? data.body : '',
        tag: typeof data.tag === 'string' && data.tag ? data.tag : undefined,
        renotify: Boolean(data.tag),
        requireInteraction: data.critical === true,
        icon: '/icons/icon-192.png',
        badge: '/icons/icon-192.png',
        data: { url },
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = new URL(event.notification.data?.url || '/home', self.location.origin);
    if (target.origin !== self.location.origin) return;
    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
        const open = windows.find((client) => new URL(client.url).origin === self.location.origin && 'focus' in client);
        if (open) return open.navigate(target.href).then((client) => (client || open).focus()).catch(() => open.focus());
        return self.clients.openWindow(target.href);
    }));
});
