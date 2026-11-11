const CACHE_PREFIX = "bretail-static-";
const CACHE_NAME = `${CACHE_PREFIX}v2`;
const APP_SHELL = ["/offline.html", "/manifest.webmanifest", "/icons/bretail.svg", "/icons/bretail-192.png", "/icons/bretail-512.png"];

self.addEventListener("install", event => {
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE_NAME);
    await cache.addAll(APP_SHELL);
    try {
      const response = await fetch("/build/manifest.json", { cache: "no-store" });
      if (response.ok) {
        const manifest = await response.json();
        const files = new Set();
        for (const entry of Object.values(manifest)) {
          if (entry.file) files.add(`/build/${entry.file}`);
          for (const file of [...(entry.css ?? []), ...(entry.assets ?? [])]) files.add(`/build/${file}`);
        }
        await cache.addAll([...files]);
      }
    } catch {
      // A first visit can still install the safe offline page if build assets are unavailable.
    }
    await self.skipWaiting();
  })());
});

self.addEventListener("activate", event => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.filter(key => key.startsWith(CACHE_PREFIX) && key !== CACHE_NAME).map(key => caches.delete(key)));
    await self.clients.claim();
  })());
});

self.addEventListener("fetch", event => {
  const request = event.request;
  if (request.method !== "GET") return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  if (request.mode === "navigate") {
    event.respondWith(fetch(request).catch(async () => (await caches.match("/offline.html")) ?? Response.error()));
    return;
  }

  if (url.pathname.startsWith("/build/") || APP_SHELL.includes(url.pathname)) {
    event.respondWith((async () => {
      const cached = await caches.match(request);
      if (cached) return cached;
      const response = await fetch(request);
      if (response.ok && url.pathname.startsWith("/build/")) {
        const cache = await caches.open(CACHE_NAME);
        cache.put(request, response.clone());
      }
      return response;
    })());
  }
  // Authenticated HTML, APIs and business records always use the network and are never cached.
});
