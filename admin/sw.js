self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));

self.addEventListener('push', (e) => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch (_) { d = { body: e.data ? e.data.text() : '' }; }
  const jobs = [
    self.registration.showNotification(d.title || 'Ny besked', {
      body: d.body || '',
      icon: '/assets/brand/dash-192.png',
      badge: '/assets/brand/fav-96.png',
      tag: d.tag || 'dm',
      renotify: true,
      data: { url: d.url || '/admin/?view=messages' }
    })
  ];
  if ('setAppBadge' in self.navigator && d.badge) jobs.push(self.navigator.setAppBadge(d.badge).catch(() => {}));
  e.waitUntil(Promise.all(jobs));
});

self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const url = new URL(e.notification.data && e.notification.data.url || '/admin/', self.location.origin).href;
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
    for (const c of list) {
      if (c.url.startsWith(self.location.origin + '/admin')) {
        return c.focus().then((w) => w.navigate ? w.navigate(url) : w);
      }
    }
    return self.clients.openWindow(url);
  }));
});
