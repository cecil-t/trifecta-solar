// Minimal service worker so the app can be installed to a phone's home screen.
// It deliberately does NOT cache pages: tracker data must always be live.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));
self.addEventListener('fetch', () => { /* network only */ });
