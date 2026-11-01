/*
 * WorkMate service worker: makes the site installable, quick to open and
 * usable offline.
 *
 * - Pages: always fetched fresh from the server when online, so opening the
 *   app loads the latest release. The last page shell is kept for offline use.
 * - Built files (/build/assets): their names change with every release, so a
 *   saved copy never goes stale. Screens are saved as they are opened. The app
 *   sends the current release's files (SYNC_BUILD): the core files are saved
 *   up front, and files from older releases are removed. Other screens aren't
 *   downloaded in advance, to save people's mobile data.
 * - API calls and sign-in (/api, /sanctum) are never stored here.
 * - Push notifications: shown as they arrive; tapping one opens the WorkMate
 *   page it names (never another site).
 *
 * Change CACHE_VERSION only when this file's caching rules change.
 */
const CACHE_VERSION = 'v1';
const SHELL_CACHE = `wm-shell-${CACHE_VERSION}`;
const BUILD_CACHE = `wm-build-${CACHE_VERSION}`;
const STATIC_CACHE = `wm-static-${CACHE_VERSION}`;
const SHELL_KEY = '/__app-shell';

// Fonts and the icon font come from these hosts; their URLs are versioned
const STATIC_HOSTS = ['fonts.googleapis.com', 'fonts.gstatic.com', 'cdn.jsdelivr.net'];

self.addEventListener('install', (event) => {
    event.waitUntil(saveShell().catch(() => {}).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    const current = [SHELL_CACHE, BUILD_CACHE, STATIC_CACHE];
    event.waitUntil((async () => {
        const names = await caches.keys();
        await Promise.all(names.filter(n => n.startsWith('wm-') && !current.includes(n)).map(n => caches.delete(n)));
        if (self.registration.navigationPreload) await self.registration.navigationPreload.enable();
        await self.clients.claim();
    })());
});

self.addEventListener('message', (event) => {
    if (event.data?.type === 'SYNC_BUILD' && Array.isArray(event.data.keep) && Array.isArray(event.data.save)) {
        event.waitUntil(syncBuild(event.data.keep, event.data.save));
    }
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    if (url.origin === self.location.origin) {
        if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/sanctum/')) return;
        if (request.mode === 'navigate') return event.respondWith(page(event));
        if (url.pathname.startsWith('/build/assets/')) return event.respondWith(cacheFirst(request, BUILD_CACHE));
        if (/^\/(images|icons)\/|^\/(favicon\.ico|manifest\.webmanifest)$/.test(url.pathname)) {
            return event.respondWith(staleWhileRevalidate(event, STATIC_CACHE));
        }
        return;
    }

    if (STATIC_HOSTS.includes(url.hostname)) event.respondWith(staleWhileRevalidate(event, STATIC_CACHE));
});

/** Network first; offline falls back to the saved shell (every page is the same Vue shell) */
async function page(event) {
    try {
        const response = (await event.preloadResponse) || await fetch(event.request);
        if (response.ok && !response.redirected && (response.headers.get('Content-Type') || '').includes('text/html')) {
            const copy = response.clone();
            event.waitUntil(caches.open(SHELL_CACHE).then(c => c.put(SHELL_KEY, copy)));
        }
        return response;
    } catch (e) {
        const saved = await caches.match(SHELL_KEY);
        return saved || offlinePage();
    }
}

async function saveShell() {
    const response = await fetch('/', { cache: 'no-store', credentials: 'same-origin' });
    if (response.ok) await (await caches.open(SHELL_CACHE)).put(SHELL_KEY, response);
}

async function cacheFirst(request, cacheName) {
    const saved = await caches.match(request);
    if (saved) return saved;
    const response = await fetch(request);
    if (response.ok) {
        const copy = response.clone();
        caches.open(cacheName).then(c => c.put(request, copy));
    }
    return response;
}

async function staleWhileRevalidate(event, cacheName) {
    const cache = await caches.open(cacheName);
    const saved = await cache.match(event.request);
    const update = fetch(event.request).then((response) => {
        if (response.ok || response.type === 'opaque') cache.put(event.request, response.clone());
        return response;
    });
    if (saved) {
        event.waitUntil(update.catch(() => {}));
        return saved;
    }
    return update;
}

/** Removes files that aren't in the current release, then saves its core files */
async function syncBuild(keep, save) {
    const cache = await caches.open(BUILD_CACHE);
    const toUrl = f => new URL(f, self.location.origin).href;
    const current = new Set(keep.map(toUrl));

    for (const request of await cache.keys()) {
        if (!current.has(request.url)) await cache.delete(request);
    }
    for (const url of save.map(toUrl)) {
        if (await cache.match(url)) continue;
        try {
            const response = await fetch(url);
            if (response.ok) await cache.put(url, response);
        } catch (e) { /* offline or slow: picked up on the next sync */ }
    }
}

/* ---------- Push notifications ---------- */

self.addEventListener('push', (event) => {
    let data = {};
    try { data = event.data ? event.data.json() : {}; } catch (e) { data = { body: event.data && event.data.text() }; }

    // Only WorkMate pages open from a notification
    const url = typeof data.url === 'string' && data.url.startsWith('/') && !data.url.startsWith('//') ? data.url : '/';
    event.waitUntil(self.registration.showNotification(data.title || 'WorkMate', {
        body: data.body || '',
        // Large picture beside the text
        icon: '/icons/icon-192.png',
        // Small status-bar icon: Android paints it white, so it must be a white shape on transparent
        badge: '/icons/badge-96.png',
        tag: data.tag,
        // A newer notification with the same tag (e.g. another message in a chat) alerts again
        renotify: !!data.tag,
        vibrate: [200, 100, 200],
        // Important ones (requests, replies, decisions) stay until dismissed where the device allows
        requireInteraction: data.important === true,
        timestamp: Date.now(),
        data: { url },
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = new URL((event.notification.data && event.notification.data.url) || '/', self.location.origin).href;

    event.waitUntil((async () => {
        // Reuse an open WorkMate window when there is one
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const client of windows) {
            if (new URL(client.url).origin !== self.location.origin) continue;
            await client.focus();
            if ('navigate' in client) await client.navigate(target).catch(() => {});
            return;
        }
        await self.clients.openWindow(target);
    })());
});

function offlinePage() {
    const html = `<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1"><title>WorkMate - Offline</title>
<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:system-ui,sans-serif;background:#F8FAFC;color:#0F172A;text-align:center;padding:24px}
button{margin-top:16px;font:inherit;font-weight:600;color:#fff;background:#1769D2;border:0;border-radius:999px;padding:12px 24px}</style></head>
<body><div><h1 style="font-size:22px">You're offline</h1><p style="color:#64748B">Check your internet connection and try again.</p>
<button onclick="location.reload()">Try again</button></div></body></html>`;
    return new Response(html, { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
}
