// Uygulama klasorunun adindan bagimsiz calismasi icin tum yollar gorelidir;
// service worker'in kendi konumuna gore cozulur.
const CACHE_NAME = "qms-cache-v149";

const APP_SHELL = [

    "offline.html",
    "assets/css/style.css",
    "assets/css/document-editor.css",
    "assets/css/risks.css",
    "assets/css/office.css",
    "assets/fonts/outfit-latin.woff2",
    "assets/fonts/outfit-latin-ext.woff2",
    "assets/js/document-editor.js",
    "assets/vendor/tinymce/tinymce.min.js",
    "assets/vendor/tinymce/langs/tr.js",
    "assets/js/theme.js",
    "assets/js/language.js",
    "assets/js/sidebar.js",
    "assets/js/pwa.js",
    "assets/js/office.js",
    "assets/icons/qms-logo.png",
    "assets/icons/qms-icon-192.png",
    "assets/icons/qms-icon-512.png",
    "assets/icons/qms-icon.svg",
    "manifest.webmanifest"
];

self.addEventListener("install", function(event) {
    event.waitUntil(
        caches.open(CACHE_NAME).then(function(cache) {
            return cache.addAll(APP_SHELL);
        })
    );

    self.skipWaiting();
});

self.addEventListener("activate", function(event) {
    event.waitUntil(
        caches.keys().then(function(cacheNames) {
            return Promise.all(
                cacheNames
                    .filter(function(cacheName) {
                        return cacheName !== CACHE_NAME;
                    })
                    .map(function(cacheName) {
                        return caches.delete(cacheName);
                    })
            );
        })
    );

    self.clients.claim();
});

self.addEventListener("fetch", function(event) {
    const requestUrl = new URL(event.request.url);
    if (event.request.method !== "GET" || requestUrl.origin !== self.location.origin || requestUrl.pathname.includes(".php") || requestUrl.searchParams.has("access_token")) {
        return;
    }

    event.respondWith(
        fetch(event.request)
            .then(function(response) {
                const responseClone = response.clone();

                caches.open(CACHE_NAME).then(function(cache) {
                    cache.put(event.request, responseClone);
                });

                return response;
            })
            .catch(function() {
                return caches.match(event.request).then(function(cachedResponse) {
                    if (cachedResponse) {
                        return cachedResponse;
                    }

                    if (event.request.mode === "navigate") {
                        return caches.match("offline.html");
                    }

                    return new Response("", {
                        status: 408,
                        statusText: "Offline"
                    });
                });
            })
    );
});
