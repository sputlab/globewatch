/* GlobeWatch — service worker (served from the site root so its scope covers the whole app).
   Strategies:
   - app shell (CSS, JS, icons, offline page): precached, cache-first (URLs are versioned with ?v=)
   - page navigations: network-first, falling back to the last cached page, then to offline.html
   - JSON API (api/*.php): network-first, falling back to the last good response (clearly "stale" data offline)
   - map tiles, fonts and Leaflet from CDNs: stale-while-revalidate with a size cap
   - admin.php, diagnostics, refresh and every non-GET request: never touched.
   Bump VERSION on every release (tools/bump-version.sh does it) so old caches are dropped. */
const VERSION = '2.1.2';
const SHELL = 'gw-shell-' + VERSION;
const PAGES = 'gw-pages-' + VERSION;
const API = 'gw-api-' + VERSION;
const TILES = 'gw-tiles-v1';
const TILE_LIMIT = 400;

const PRECACHE = [
  'offline.html',
  'assets/css/app.css?v=' + VERSION,
  'assets/js/app.js?v=' + VERSION,
  'assets/js/i18n.js?v=' + VERSION,
  'assets/img/favicon.svg',
  'assets/icons/icon-192.png',
  'assets/icons/icon-512.png',
  'manifest.webmanifest',
];
const CDN_HOSTS = ['basemaps.cartocdn.com', 'a.basemaps.cartocdn.com', 'b.basemaps.cartocdn.com', 'c.basemaps.cartocdn.com', 'd.basemaps.cartocdn.com',
  'cdnjs.cloudflare.com', 'fonts.googleapis.com', 'fonts.gstatic.com'];
const NEVER = /\/(admin\.php|api\/(diagnostics|refresh)\.php)/;

self.addEventListener('install', event => {
  event.waitUntil(caches.open(SHELL).then(c => c.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', event => {
  event.waitUntil((async () => {
    const keep = new Set([SHELL, PAGES, API, TILES]);
    for (const k of await caches.keys()) if (k.startsWith('gw-') && !keep.has(k)) await caches.delete(k);
    if (self.registration.navigationPreload) await self.registration.navigationPreload.enable();
    await self.clients.claim();
  })());
});

self.addEventListener('message', event => { if (event.data === 'skipWaiting') self.skipWaiting(); });

async function trim(cacheName, max){
  const cache = await caches.open(cacheName), keys = await cache.keys();
  for (let i = 0; i < keys.length - max; i++) await cache.delete(keys[i]);
}
async function networkFirst(request, cacheName, fallbackUrl, preload){
  const cache = await caches.open(cacheName);
  try {
    const res = (preload && await preload) || await fetch(request);
    if (res && res.ok) cache.put(request, res.clone());
    return res;
  } catch (e) {
    const hit = await cache.match(request, {ignoreSearch: cacheName === PAGES});
    if (hit) return hit;
    if (fallbackUrl) { const f = await caches.match(fallbackUrl); if (f) return f; }
    throw e;
  }
}
async function staleWhileRevalidate(request, cacheName, limit){
  const cache = await caches.open(cacheName), hit = await cache.match(request);
  const net = fetch(request).then(res => {
    if (res && (res.ok || res.type === 'opaque')) { cache.put(request, res.clone()); if (limit) trim(cacheName, limit); }
    return res;
  }).catch(() => null);
  return hit || (await net) || Response.error();
}

self.addEventListener('fetch', event => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);

  if (url.origin === location.origin) {
    if (NEVER.test(url.pathname)) return;
    if (req.mode === 'navigate') {
      event.respondWith(networkFirst(req, PAGES, 'offline.html', event.preloadResponse));
      return;
    }
    if (/\/api\/[^/]+\.php$/.test(url.pathname)) {
      event.respondWith(networkFirst(req, API));
      return;
    }
    if (/\.(css|js|png|svg|webmanifest|ico)$/.test(url.pathname)) {
      event.respondWith(caches.match(req).then(hit => hit || fetch(req).then(res => {
        if (res.ok) caches.open(SHELL).then(c => c.put(req, res.clone()));
        return res;
      })));
    }
    return;
  }
  if (CDN_HOSTS.includes(url.hostname)) {
    event.respondWith(staleWhileRevalidate(req, url.hostname.includes('cartocdn') ? TILES : SHELL, url.hostname.includes('cartocdn') ? TILE_LIMIT : 0));
  }
});
