/* Bharat GPS Chat alerts (same as ScanPlay) for the agent's browser (owner, 3 Oct 2026). The server sends an empty push for every
   visit, new chat and visitor message; this asks api.php what happened and shows it as a notification. */
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });

self.addEventListener('push', function (e) {
  e.waitUntil(self.registration.pushManager.getSubscription().then(function (s) {
    var f = new FormData(); f.append('action', 'push_info'); f.append('endpoint', s ? s.endpoint : '');
    return fetch('api.php?action=push_info&_=' + Date.now(), { method: 'POST', body: f, cache: 'no-store' }).then(function (r) { return r.json(); });
  }).then(function (r) {
    var ev = r && r.ok && r.event;
    return self.registration.showNotification(ev ? ev.title : 'Bharat GPS Chat', {
      body: ev ? ev.body : 'New activity on the website chat',
      tag: ev ? 'ev' + ev.id : 'ev', renotify: true, requireInteraction: false,
      vibrate: [300, 150, 300], data: { chat: ev ? ev.chat : 0 }
    });
  }).catch(function () {
    return self.registration.showNotification('Bharat GPS Chat', { body: 'New activity on the website chat', tag: 'ev' });
  }));
});

self.addEventListener('notificationclick', function (e) {
  e.notification.close();
  var chat = (e.notification.data && e.notification.data.chat) || 0;
  var url = new URL('agent.html' + (chat ? '?chat=' + chat : ''), self.registration.scope).href;
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
    for (var i = 0; i < list.length; i++) {
      var c = list[i];
      if (c.url.indexOf('/livechat/agent.html') !== -1 && 'focus' in c) {
        if (chat) c.postMessage({ openChat: chat });
        return c.focus();
      }
    }
    return self.clients.openWindow(url);
  }));
});
