const CACHE_NAME = 'news-pwa-v3';
const BASE_PATH = '/news/';

self.addEventListener('install', function(e) {
  self.skipWaiting();
});

self.addEventListener('activate', function(e) {
  e.waitUntil(
    caches.keys().then(function(keys) {
      return Promise.all(
        keys.filter(function(k){ return k !== CACHE_NAME; })
            .map(function(k){ return caches.delete(k); })
      );
    }).then(function() {
      return self.clients.claim();
    })
  );
});

self.addEventListener('fetch', function(e) {
  var url = e.request.url;

  // ✦ Only handle requests from our own origin
  if (!url.startsWith(self.location.origin)) {
    return; // Cross-origin request — let browser handle it natively
  }

  // ✦ Only handle requests within our scope
  if (!url.includes(BASE_PATH)) {
    return; // Let browser handle it
  }

  // ✦ Never cache: API calls, POST requests, PHP files (always fresh)
  if (
    url.includes('?api') ||
    url.includes('fetch.php') ||
    url.includes('news.php') ||
    url.includes('manifest.php') ||
    e.request.method === 'POST'
  ) {
    // Network-only with offline fallback
    return e.respondWith(
      fetch(e.request).catch(function(err) {
        // Return offline fallback JSON for API calls
        if (url.includes('?api')) {
          return new Response(
            JSON.stringify({
              ok: false,
              offline: true,
              count: 0,
              entries: [],
              stats: {},
              channels: [],
              channel_names: {}
            }),
            {
              status: 200,
              statusText: 'Offline',
              headers: { 'Content-Type': 'application/json; charset=utf-8' }
            }
          );
        }
        throw err;
      })
    );
  }

  // ✦ Static assets: cache-first with network fallback
  e.respondWith(
    caches.match(e.request).then(function(cached) {
      if (cached) return cached;

      return fetch(e.request).then(function(resp) {
        if (resp && resp.status === 200 && resp.type !== 'opaque') {
          var clone = resp.clone();
          caches.open(CACHE_NAME).then(function(cache) {
            cache.put(e.request, clone);
          }).catch(function(){});
        }
        return resp;
      }).catch(function() {
        return new Response(
          'آفلاین - نسخه کش شده موجود نیست',
          {
            status: 503,
            statusText: 'Service Unavailable',
            headers: { 'Content-Type': 'text/plain; charset=utf-8' }
          }
        );
      });
    })
  );
});

self.addEventListener('notificationclick', function(event) {
  event.notification.close();
  var url = self.location.origin + BASE_PATH + 'news.php';

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true })
      .then(function(list) {
        for (var i = 0; i < list.length; i++) {
          if ('focus' in list[i]) return list[i].focus();
        }
        if (clients.openWindow) return clients.openWindow(url);
      })
  );
});