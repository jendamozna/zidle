// Service worker of the organizer scanner: keeps scanner.html and its built
// assets on the device, so the scanner opens without a connection.
//  - scanner.html: network first (always the current version when online), cached copy offline
//  - assets/*: hashed file names never change → cache first
//  - everything else (API, customer app pages, fonts) goes to the network untouched
const CACHE = 'zidle-scanner-v1';
const PAGE = new URL('scanner.html', self.registration.scope).href;
const ASSETS = new URL('assets/', self.registration.scope).href;

/** Absolute URLs of the built assets a scanner.html refers to. */
const assetsOf = (html) =>
  [...html.matchAll(/(?:src|href)="([^"]*assets\/[^"]+)"/g)].map((m) => new URL(m[1], PAGE).href);

/** Stores the page and its assets; removes assets of older builds. */
async function storePage(response) {
  const cache = await caches.open(CACHE);
  const html = await response.clone().text();
  const assets = assetsOf(html);
  await cache.addAll(assets.filter((url) => url.startsWith(ASSETS)));
  await cache.put(PAGE, response);
  const keep = new Set(assets);
  for (const request of await cache.keys()) {
    if (request.url.startsWith(ASSETS) && !keep.has(request.url)) await cache.delete(request);
  }
}

self.addEventListener('install', (event) => {
  event.waitUntil(
    fetch(PAGE, { cache: 'no-store' })
      .then((response) => (response.ok ? storePage(response) : undefined))
      .then(() => self.skipWaiting()),
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  url.search = '';

  if (request.mode === 'navigate' && url.href === PAGE) {
    event.respondWith(
      fetch(request)
        .then((response) => {
          if (response.ok) event.waitUntil(storePage(response.clone()));
          return response;
        })
        .catch(async () => (await caches.match(PAGE)) ?? Response.error()),
    );
    return;
  }

  if (url.href.startsWith(ASSETS)) {
    event.respondWith(
      caches.match(request).then(
        (cached) =>
          cached ??
          fetch(request).then(async (response) => {
            if (response.ok) await (await caches.open(CACHE)).put(request, response.clone());
            return response;
          }),
      ),
    );
  }
});
