/* Vireo Service Worker – App-Shell-Cache für die PWA.
 * Wird beim Build von vite.config.ts (serviceWorkerPlugin) mit der Dateiliste befüllt.
 * Grundsatz: Es werden nur eigene, statische Dateien gecacht. API-Aufrufe (anderer
 * Ursprung bzw. /api/) laufen immer ungecacht durch; Offline-Daten verwaltet die App
 * verschlüsselt in IndexedDB. */
const CACHE_VERSION = __CACHE_VERSION__;
const PRECACHE = __PRECACHE_FILES__;
const CACHE_NAME = 'vireo-shell-' + CACHE_VERSION;

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting()),
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) => Promise.all(keys.filter((k) => k.startsWith('vireo-shell-') && k !== CACHE_NAME).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return; // API und externe Ressourcen nie anfassen
  if (url.pathname.includes('/api/')) return;

  if (req.mode === 'navigate') {
    // App-Shell: zuerst Netz (für Updates), sonst gecachte index.html
    event.respondWith(
      fetch(req).catch(() => caches.match('./', { ignoreSearch: true }).then((r) => r || caches.match('./index.html'))),
    );
    return;
  }

  event.respondWith(
    caches.match(req).then((cached) => cached || fetch(req)),
  );
});
