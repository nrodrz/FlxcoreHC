/* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
 * BT147: the installed app's only helper. It keeps ONE page — the branded "You need an Internet connection to connect"
 * page — and shows it when a page of the app cannot be opened because the device is offline. It keeps no app code, no
 * records and no sign-in; every request goes to the server exactly as without it. Signing out (Clear-Site-Data) or
 * uninstalling the app removes it and that page. */
const KEEP = 'app-offline-v1';
const offlineUrl = () => new URL('api/app/offline', self.registration.scope).href;
const keepPage = () => caches.open(KEEP).then(c => c.add(new Request(offlineUrl(), { cache: 'reload', credentials: 'omit' })));
self.addEventListener('install', e => { e.waitUntil(keepPage().catch(() => {}).then(() => self.skipWaiting())); });
self.addEventListener('activate', e => { e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k !== KEEP).map(k => caches.delete(k)))).then(() => self.clients.claim())); });
self.addEventListener('message', e => { if (e.data === 'refresh-offline') keepPage().catch(() => {}); });
self.addEventListener('fetch', e => {
  if (e.request.mode !== 'navigate') return;   // only whole pages; everything else is never touched
  e.respondWith(fetch(e.request).catch(() => caches.match(offlineUrl()).then(r => r || Response.error())));
});
