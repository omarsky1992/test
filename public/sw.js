// Minimal service worker so the app can be installed on a phone's home screen.
// It does not cache anything: financial data must always come live from the server.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));
self.addEventListener('fetch', () => {});
