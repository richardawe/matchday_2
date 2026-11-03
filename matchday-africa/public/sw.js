// Matchday Africa service worker (registered on every page, scope /). It makes the site installable
// as an app on iPhone and Android, shows match alerts and messages pushed by the site
// (App\Services\Alerts\WebPush) and opens the right page when one is tapped. Pages, media and API
// calls always come from the network; only the offline screen is cached, for when there is no signal.
const OFFLINE = '/offline.html';
const CACHE = 'matchday-offline-v1';

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.add(new Request(OFFLINE, { cache: 'reload' }))).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(caches.keys()
        .then((keys) => Promise.all(keys.filter((key) => key.startsWith('matchday-') && key !== CACHE).map((key) => caches.delete(key))))
        .then(() => self.clients.claim()));
});

// Only page loads are touched, and only when the network fails: no stale scores, and media keeps
// its byte-range requests straight to the server.
self.addEventListener('fetch', (event) => {
    if (event.request.mode !== 'navigate' || event.request.method !== 'GET') return;
    event.respondWith(fetch(event.request).catch(() => caches.match(OFFLINE).then((page) => page || Response.error())));
});

self.addEventListener('push', (event) => {
    let data = {};
    try { data = event.data ? event.data.json() : {}; } catch (_) { data = { body: event.data?.text() }; }
    const title = data.title || 'Matchday Africa';
    event.waitUntil(self.registration.showNotification(title, {
        body: data.body || '',
        icon: data.icon || '/icons/icon-192.png',
        // Android's status bar draws only the badge's shape: a white "MD" on transparent.
        badge: '/icons/badge-96.png',
        // One notification per match: a new goal replaces the last one instead of piling up.
        tag: data.tag || 'matchday',
        renotify: true,
        data: { url: data.url || '/notifications' },
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = new URL(event.notification.data?.url || '/notifications', self.location.origin);
    if (url.origin !== self.location.origin) return;
    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
        const open = windows.find((w) => w.url === url.href);
        if (open) return open.focus();
        // In the installed app, reuse its window rather than opening a browser tab.
        const app = windows.find((w) => new URL(w.url).origin === url.origin && 'navigate' in w);
        return app ? app.navigate(url.href).then((w) => (w || app).focus()) : self.clients.openWindow(url.href);
    }));
});
