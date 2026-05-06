/**
 * BravoCollab service worker.
 *
 * Push model is "VAPID-only, no encrypted payload": the server signals that
 * something arrived, the worker fetches the latest unread notification from
 * the API and renders the system notification. Sidesteps the complexity of
 * RFC 8291 payload encryption while still working everywhere Web Push does.
 */

const SW_VERSION = 'bc-sw-v1';
const APP_ICON   = '/public/img/icon-192.png';
const APP_BADGE  = '/public/img/icon-192.png';

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

// On push, fetch the latest queued notification for this user from the API
// and render it. If the fetch fails (e.g. logged out), fall back to a
// generic "you have an update" notification so the user knows to look.
self.addEventListener('push', (event) => {
    event.waitUntil((async () => {
        let title = 'BravoCollab';
        let body  = 'You have a new notification.';
        let url   = '/index.php?page=dashboard';
        let tag   = 'bc-' + Date.now();

        // The push payload (when the server includes one — currently it
        // doesn't) is JSON. We try to parse it; otherwise fall back to API.
        let payload = null;
        try {
            if (event.data) payload = event.data.json();
        } catch (e) { /* ignore */ }

        try {
            if (payload && payload.title) {
                title = payload.title;
                if (payload.body) body = payload.body;
                if (payload.url)  url  = payload.url;
                if (payload.tag)  tag  = payload.tag;
            } else {
                const res = await fetch('/api.php?action=push.latest', {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                });
                if (res.ok) {
                    const data = await res.json();
                    if (data && data.title) {
                        title = data.title;
                        body  = data.body || body;
                        url   = data.url  || url;
                        tag   = data.tag  || tag;
                    }
                }
            }
        } catch (e) { /* show fallback */ }

        await self.registration.showNotification(title, {
            body,
            icon: APP_ICON,
            badge: APP_BADGE,
            tag,
            data: { url },
            renotify: false,
        });
    })());
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = (event.notification.data && event.notification.data.url) || '/';
    event.waitUntil((async () => {
        // Focus an existing tab on the same origin instead of opening a new
        // one whenever possible.
        const wins = await clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const w of wins) {
            try {
                const u = new URL(w.url);
                if (u.origin === self.location.origin) {
                    await w.focus();
                    if ('navigate' in w) await w.navigate(url);
                    return;
                }
            } catch (e) { /* ignore */ }
        }
        await clients.openWindow(url);
    })());
});
