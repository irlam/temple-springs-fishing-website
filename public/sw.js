// Deliberately cache only immutable public assets, NEVER pages or request queries.
const CACHE='temple-springs-assets-v3';
const ASSETS=['/styles.css','/fishery.css','/fishery.js','/assets/mark.svg','/assets/landscape.svg','/assets/icon-192.png','/assets/icon-512.png'];
self.addEventListener('install',event=>{event.waitUntil(caches.open(CACHE).then(c=>c.addAll(ASSETS)).then(()=>self.skipWaiting()));});
self.addEventListener('activate',event=>{event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(k=>k.startsWith('temple-springs-')&&k!==CACHE).map(k=>caches.delete(k)))).then(()=>self.clients.claim()));});
self.addEventListener('fetch',event=>{
  const u=new URL(event.request.url);
  if(event.request.method!=='GET'||u.origin!==self.location.origin||u.search||!ASSETS.includes(u.pathname))return;
  event.respondWith(fetch(event.request).catch(async()=>await caches.match(event.request)||Response.error()));
});
