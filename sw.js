/* Service Worker — Agenda Social PWA
   Estratégia:
   - Estáticos (css/js/fontes/ícones): cache-first (rápido e offline).
   - Navegações/páginas (GET): network-first com fallback ao cache.
   - POST e tudo mais: passa direto pela rede (nunca cacheia ação).
   Troque APP_CACHE ao mudar a versão p/ forçar atualização. */
const APP_CACHE = 'agenda-social-v3.10.2';
const SHELL = [
  'app.css',
  'icons/icon-192.png',
  'icons/icon-512.png',
  'manifest.json',
];

self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(APP_CACHE).then((c) => c.addAll(SHELL.map((u) => new Request(u, { cache: 'reload' })))).catch(() => {})
  );
  self.skipWaiting();
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== APP_CACHE).map((k) => caches.delete(k))))
  );
  self.clients.claim();
});

/* ---- Web Push (alertas de ônibus "tá chegando") ---- */
self.addEventListener('push', (e) => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch (err) { d = { body: e.data ? e.data.text() : '' }; }
  const title = d.title || '🚍 Ônibus Maceió';
  const opts = {
    body: d.body || '',
    icon: d.icon || 'icons/icon-192.png',
    badge: 'icons/icon-192.png',
    tag: d.tag || 'onibus-alerta',
    renotify: true,
    data: { url: d.url || 'local.php' },
  };
  e.waitUntil(self.registration.showNotification(title, opts));
});

self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const url = (e.notification.data && e.notification.data.url) || 'local.php';
  e.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
      for (const c of list) { if (c.url.includes('local.php') && 'focus' in c) return c.focus(); }
      return clients.openWindow ? clients.openWindow(url) : null;
    })
  );
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return; // nunca intercepta POST (ações/login/aprovação)

  const url = new URL(req.url);
  if (url.origin !== location.origin) return; // deixa CDNs/Graph passarem

  const ehEstatico = /\.(css|js|png|jpg|jpeg|webp|svg|woff2?|ttf|ico)$/i.test(url.pathname);

  if (ehEstatico) {
    e.respondWith(
      caches.match(req).then((hit) => hit || fetch(req).then((res) => {
        const copy = res.clone();
        caches.open(APP_CACHE).then((c) => c.put(req, copy)).catch(() => {});
        return res;
      }).catch(() => hit))
    );
    return;
  }

  // páginas: network-first, cai p/ cache se offline
  e.respondWith(
    fetch(req).then((res) => {
      const copy = res.clone();
      caches.open(APP_CACHE).then((c) => c.put(req, copy)).catch(() => {});
      return res;
    }).catch(() => caches.match(req).then((hit) => hit || caches.match('dashboard.php')))
  );
});
