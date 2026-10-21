// Service worker: installability, plus offline copies of the newest unread
// articles.
//
// Whenever an app page is opened online it asks this worker to sync. A sync
// reads /offline/articles (the list the server wants kept), fetches any
// reader page that is new or has changed, with its stored images and the
// built CSS and JS it uses, and deletes everything that is no longer on the
// list. Offline, a reader page in the list is served from that copy and any
// other page falls back to /offline.html, which lists what is available.
//
// Pages are always fetched from the network first, so online behaviour is
// unchanged. Nothing is kept while logged out: a sync that finds the session
// gone deletes the copies, and logging out clears them as well.
const SHELL = 'docket-shell-v2';
const ARTICLES = 'docket-articles';
const ASSETS = 'docket-assets';
const KEEP = [SHELL, ARTICLES, ASSETS];

const OFFLINE_URL = '/offline.html';
const SHELL_FILES = [OFFLINE_URL, '/offline.js'];
const LIST_URL = '/offline/articles';

// Where the sync records what it kept, for /offline.html and the next sync.
// Never requested from the server.
const INDEX_KEY = '/__docket/offline-index';

const IMAGE = /^\/items\/\d+\/images\/[a-f0-9]{40}\.(?:jpg|png|gif|webp|avif)$/;
const ASSET = /\/build\/assets\/[^"'\s)]+/g;

// A sync is skipped if one finished this recently.
const MIN_INTERVAL = 30 * 1000;

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(SHELL).then((cache) => cache.addAll(SHELL_FILES))
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((k) => !KEEP.includes(k)).map((k) => caches.delete(k)))
        )
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(navigate(request, url));
    } else if (SHELL_FILES.includes(url.pathname)) {
        event.respondWith(fetch(request).catch(() => caches.match(request)));
    } else if (url.pathname.startsWith('/build/')) {
        event.respondWith(cacheFirst(ASSETS, request));
    } else if (IMAGE.test(url.pathname)) {
        event.respondWith(cached(ARTICLES, url.pathname).then((hit) => hit || fetch(request)));
    }
});

self.addEventListener('message', (event) => {
    const type = event.data && event.data.type;

    if (type === 'sync') {
        event.waitUntil(sync());
    } else if (type === 'clear') {
        event.waitUntil(clear());
    }
});

async function navigate(request, url) {
    try {
        return await fetch(request);
    } catch (error) {
        return (await cached(ARTICLES, url.pathname))
            || (await caches.match(OFFLINE_URL))
            || Response.error();
    }
}

async function cacheFirst(name, request) {
    const hit = await caches.match(request);

    if (hit) {
        return hit;
    }

    const response = await fetch(request);

    if (response.ok) {
        const cache = await caches.open(name);
        await cache.put(request, response.clone());
    }

    return response;
}

async function cached(name, path) {
    const cache = await caches.open(name);

    return cache.match(path);
}

// A same-origin GET that only counts if it really came back: a redirect
// here means the session has gone and the login page answered instead.
async function get(path) {
    try {
        const response = await fetch(path, { credentials: 'same-origin', cache: 'no-store' });

        return response.ok && !response.redirected ? response : null;
    } catch (error) {
        return null;
    }
}

async function clear() {
    await caches.delete(ARTICLES);
    await caches.delete(ASSETS);
}

let running = null;
let finishedAt = 0;

function sync() {
    if (!running && Date.now() - finishedAt >= MIN_INTERVAL) {
        running = runSync().finally(() => {
            running = null;
            finishedAt = Date.now();
        });
    }

    return running || Promise.resolve();
}

async function runSync() {
    let response;

    try {
        response = await fetch(LIST_URL, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' },
        });
    } catch (error) {
        // Offline or the server is down: keep what is already here.
        return;
    }

    if (response.status === 401 || response.status === 419 || response.redirected) {
        await clear();

        return;
    }

    if (!response.ok) {
        return;
    }

    const { items } = await response.json();
    const cache = await caches.open(ARTICLES);
    const previous = await readIndex(cache);
    const kept = [];

    for (const item of items) {
        const before = previous.get(item.url);
        let stored = Boolean(before && await cache.match(item.url));
        let version = stored ? before.version : null;
        let assets = stored ? before.assets : [];

        if (version !== item.version) {
            const page = await get(item.url);

            if (page) {
                assets = [...new Set((await page.clone().text()).match(ASSET) || [])];
                await cache.put(item.url, page);
                stored = true;
                version = item.version;
            }
        }

        // A page that failed to refresh keeps its older copy (and older
        // version, so the next sync tries again), if it has one.
        if (!stored) {
            continue;
        }

        for (const src of item.images) {
            if (!(await cache.match(src))) {
                const image = await get(src);

                if (image) {
                    await cache.put(src, image);
                }
            }
        }

        kept.push({ ...item, version, assets });
    }

    await storeAssets(kept);
    await prune(cache, kept);

    await cache.put(INDEX_KEY, new Response(
        JSON.stringify({ syncedAt: new Date().toISOString(), items: kept }),
        { headers: { 'Content-Type': 'application/json' } }
    ));
}

async function readIndex(cache) {
    const map = new Map();

    try {
        const response = await cache.match(INDEX_KEY);
        const index = response ? await response.json() : { items: [] };

        for (const item of index.items) {
            map.set(item.url, item);
        }
    } catch (error) {
        // A damaged index only means everything is fetched again.
    }

    return map;
}

// The built CSS and JS the kept pages load. A deploy changes their hashed
// names, so the copies an older page needs are kept until that page is
// refreshed, and anything no kept page uses is dropped.
async function storeAssets(kept) {
    const wanted = new Set(kept.flatMap((item) => item.assets));
    const cache = await caches.open(ASSETS);

    for (const path of wanted) {
        if (!(await cache.match(path))) {
            const asset = await get(path);

            if (asset) {
                await cache.put(path, asset);
            }
        }
    }

    for (const request of await cache.keys()) {
        if (!wanted.has(new URL(request.url).pathname)) {
            await cache.delete(request);
        }
    }
}

async function prune(cache, kept) {
    const wanted = new Set([INDEX_KEY]);

    for (const item of kept) {
        wanted.add(item.url);
        item.images.forEach((src) => wanted.add(src));
    }

    for (const request of await cache.keys()) {
        if (!wanted.has(new URL(request.url).pathname)) {
            await cache.delete(request);
        }
    }
}
