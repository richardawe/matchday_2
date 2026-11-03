// Matchday Africa service worker: shows match alerts and messages pushed by the site
// (App\Services\Alerts\WebPush) and opens the right page when one is tapped. No caching.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    let data = {};
    try { data = event.data ? event.data.json() : {}; } catch (_) { data = { body: event.data?.text() }; }
    const title = data.title || 'Matchday Africa';
    event.waitUntil(self.registration.showNotification(title, {
        body: data.body || '',
        icon: data.icon || '/favicon.png',
        badge: '/favicon.png',
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
        return open ? open.focus() : self.clients.openWindow(url.href);
    }));
});
