/* ==========================================================================
   Meridian POS — Service worker

   Purpose and limits
   ------------------
   This worker exists so a cashier terminal can *load* the POS screen while the
   connection is down. It does NOT make offline transactions safe on its own —
   that is `offline.js`, which persists each queued sale in IndexedDB with an
   idempotency key and pushes it to the server afterwards.

   Rules:
     * The POS document and the static assets are cached, because they are
       needed to keep selling.
     * Every other request goes to the network first and is never cached
       silently. Caching a financial API response would be dangerous: a stale
       price or stock figure must never be presented as current.
     * Offline, non-cacheable requests return a clear JSON failure rather than a
       stale 200, so the client knows it must queue instead of trusting a reply.
   ========================================================================== */
'use strict';

/*
 * Bump this whenever STATIC_ASSETS changes. The activate handler deletes every
 * cache under a different name, which is what retires the stale copies.
 */
const VERSION = 'v4';
const STATIC_CACHE = 'meridian-static-' + VERSION;
const PAGE_CACHE = 'meridian-pages-' + VERSION;

const STATIC_ASSETS = [
    '/css/base.css',
    '/css/auth.css',
    '/css/buttons.css',
    '/css/forms.css',
    '/css/components.css',
    '/css/layout.css',
    '/css/pos.css',
    '/css/receipt.css',
    '/css/rtl-responsive.css',
    '/js/app.js',
    '/js/bulk.js',
    '/js/select.js',
    '/js/pos.js',
    '/js/offline.js',
    '/js/purchase.js',
];

/** Screens that must remain reachable with no connection. */
const OFFLINE_PAGES = ['/pos'];

/** Never cache these: they are financial data or mutate state. */
const NEVER_CACHE = ['/sync/', '/pos/search', '/pos/customers', '/pos/sale'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then((cache) => cache.addAll(STATIC_ASSETS))
            .then(() => self.skipWaiting())
            .catch(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys
                    .filter((key) => key !== STATIC_CACHE && key !== PAGE_CACHE)
                    .map((key) => caches.delete(key))
            ))
            .then(() => self.clients.claim())
    );
});

function isStaticAsset(url) {
    return url.pathname.startsWith('/css/')
        || url.pathname.startsWith('/js/')
        || url.pathname === '/favicon.ico';
}

function shouldNeverCache(pathname) {
    return NEVER_CACHE.some((prefix) => pathname.startsWith(prefix));
}

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return; // Never touch a mutation; the page decides what to do.
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    // ---- Static assets: network first, cache as the offline fallback --------
    /*
     * These used to be served cache-first, which is the classic way to ship a
     * fix that reaches the server but never reaches a till: the worker answers
     * from its own copy forever, and because its cache name never changed, no
     * deploy ever retired it. A cashier's terminal would run months-old
     * JavaScript against a current server.
     *
     * Network-first is the right trade here. A till needs these files when the
     * connection is down, which is rare; it does not need them to be stale
     * when the connection is up, which is the rest of the time.
     */
    if (isStaticAsset(url)) {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    if (response.ok) {
                        const copy = response.clone();
                        caches.open(STATIC_CACHE).then((cache) => cache.put(request, copy));
                    }

                    return response;
                })
                .catch(() => caches.match(request).then((cached) => {
                    if (cached) {
                        return cached;
                    }

                    return new Response('', { status: 504, statusText: 'Asset unavailable offline' });
                }))
        );
        return;
    }

    // ---- Financial / sync endpoints: never serve a cached reply -------------
    if (shouldNeverCache(url.pathname)) {
        event.respondWith(
            fetch(request).catch(() => new Response(
                JSON.stringify({
                    offline: true,
                    message: 'This request needs the server. Queue it locally and retry.',
                }),
                { status: 503, headers: { 'Content-Type': 'application/json' } }
            ))
        );
        return;
    }

    // ---- Documents: network first, fall back to cache for POS screens ------
    if (request.mode === 'navigate' || request.headers.get('accept')?.includes('text/html')) {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    if (response.ok && OFFLINE_PAGES.some((page) => url.pathname.startsWith(page))) {
                        const copy = response.clone();
                        caches.open(PAGE_CACHE).then((cache) => cache.put(request, copy));
                    }
                    return response;
                })
                .catch(() => caches.match(request).then((cached) => {
                    if (cached) {
                        return cached;
                    }
                    if (OFFLINE_PAGES.some((page) => url.pathname.startsWith(page))) {
                        return caches.match('/pos');
                    }
                    return new Response(
                        '<!doctype html><meta charset="utf-8"><title>Offline</title>' +
                        '<body style="font-family:system-ui;padding:2rem">' +
                        '<h1>You are offline</h1>' +
                        '<p>This screen is not cached. The point of sale terminal keeps working without a connection.</p>',
                        { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
                    );
                }))
        );
    }
});

// Let the page ask the worker to activate a new version immediately.
self.addEventListener('message', (event) => {
    if (event.data === 'skip-waiting') {
        self.skipWaiting();
    }
});
