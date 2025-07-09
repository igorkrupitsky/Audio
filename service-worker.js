// Update this cache name when you change any of the cached URLs
const CACHE_NAME = 'audio-player-app-29';

const urlsToCache = [
    'Player.js?v=29',
    'Player.css?v=29',
    'Player.php',
    'manifest.json',
    'images/icon192.png',
    'images/icon512.png'
];

// Install event - cache all specified files
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => {
                return cache.addAll(urlsToCache);
            })
            .then(() => self.skipWaiting())
    );
});

// Activate event - remove old caches
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames.map(name => {
                    if (name !== CACHE_NAME) {
                        return caches.delete(name);
                    }
                })
            );
        }).then(() => self.clients.claim())
    );
});

// Fetch event - respond with cache or fallback to network
self.addEventListener('fetch', event => {
    event.respondWith(
        caches.match(event.request, { ignoreSearch: false }).then(response => {
            return response || fetch(event.request);
        })
    );
});
