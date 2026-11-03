/**
 * Gallery Service Worker
 *
 * Caching strategies:
 *   - Static assets (JS, CSS, fonts): Cache-first, versioned by cache name
 *   - Thumbnails (/media/thumbs/*):   Cache-first, LRU eviction at 2000 entries
 *   - API media lists (/api/media/page|untagged|with-tags/…, /api/media?cursor=…):
 *                                     Network-first, cache fallback for offline
 *   - Full-size media:                Network-only (too large to cache)
 *
 * Adjacent page pre-caching:
 *   When the app sends a PREFETCH_THUMBNAILS message with file names,
 *   the SW fetches those thumbnails in the background and caches them.
 */

// Bumped for API 5: the media listings are matched by their real paths (the
// old rule only knew the long-gone `/api/media?page=…` form, so no listing was
// ever cached), and infinite scroll now reads cursor batches. Bumping renames
// every cache, so `activate` purges the old ones instead of serving a mix.
const CACHE_VERSION = 'v4'
const STATIC_CACHE = `gallery-static-${CACHE_VERSION}`
const THUMB_CACHE = `gallery-thumbs-${CACHE_VERSION}`
const API_CACHE = `gallery-api-${CACHE_VERSION}`

const MAX_THUMB_ENTRIES = 2000
const MAX_STATIC_ENTRIES = 100
// Every page and cursor is its own URL, so the listing cache is capped too.
const MAX_API_ENTRIES = 200

/** The media listings: numbered pages and the cursor feed (GET /api/media). */
const MEDIA_LIST_PREFIXES = ['/api/media/page/', '/api/media/untagged/', '/api/media/with-tags/']

function isMediaList(pathname) {
  return pathname === '/api/media' || MEDIA_LIST_PREFIXES.some((p) => pathname.startsWith(p))
}

// ─── Install ────────────────────────────────────────────────────────

self.addEventListener('install', (event) => {
  // Activate immediately without waiting for existing tabs to close
  self.skipWaiting()
})

// ─── Activate ───────────────────────────────────────────────────────

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) => {
        return Promise.all(
          keys
            .filter((key) => key !== STATIC_CACHE && key !== THUMB_CACHE && key !== API_CACHE)
            .map((key) => caches.delete(key)),
        )
      })
      .then(() => self.clients.claim()),
  )
})

// ─── Fetch ──────────────────────────────────────────────────────────

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url)

  // Only handle GET requests from our own origin
  if (event.request.method !== 'GET' || url.origin !== self.location.origin) {
    return
  }

  // Thumbnails: cache-first
  if (url.pathname.startsWith('/media/thumbs/')) {
    event.respondWith(cacheFirst(event.request, THUMB_CACHE))
    return
  }

  // Static assets (Vite bundles, favicon, icons): cache-first
  if (
    url.pathname.startsWith('/assets/') ||
    url.pathname === '/favicon.png' ||
    url.pathname === '/icons.svg'
  ) {
    event.respondWith(cacheFirst(event.request, STATIC_CACHE))
    return
  }

  // API gallery listings: network-first with cache fallback
  if (isMediaList(url.pathname)) {
    event.respondWith(networkFirst(event.request, API_CACHE))
    return
  }

  // Everything else: network-only (full-size images, admin endpoints, etc.)
})

// ─── Message handler for prefetch ───────────────────────────────────

self.addEventListener('message', (event) => {
  if (event.data?.type === 'PREFETCH_THUMBNAILS') {
    const urls = event.data.urls
    if (Array.isArray(urls) && urls.length > 0) {
      event.waitUntil(prefetchThumbnails(urls))
    }
  }
})

// ─── Caching strategies ─────────────────────────────────────────────

async function cacheFirst(request, cacheName) {
  const cached = await caches.match(request)
  if (cached) {
    return cached
  }

  try {
    const response = await fetch(request)
    if (response.ok) {
      const cache = await caches.open(cacheName)
      cache.put(request, response.clone())

      // Evict old entries. Content-hashed asset filenames mean that across
      // deploys the static cache would otherwise grow without bound, since the
      // cache name (and thus the activate-time purge) never changes.
      if (cacheName === THUMB_CACHE) {
        trimCache(cacheName, MAX_THUMB_ENTRIES)
      } else if (cacheName === STATIC_CACHE) {
        trimCache(cacheName, MAX_STATIC_ENTRIES)
      }
    }
    return response
  } catch (err) {
    // Offline and not cached — return a transparent 1x1 pixel for images
    if (request.destination === 'image') {
      return new Response(
        Uint8Array.from(atob('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), (c) =>
          c.charCodeAt(0),
        ),
        { headers: { 'Content-Type': 'image/gif' } },
      )
    }
    throw err
  }
}

async function networkFirst(request, cacheName) {
  try {
    const response = await fetch(request)
    if (response.ok) {
      const cache = await caches.open(cacheName)
      cache.put(request, response.clone())
      trimCache(cacheName, MAX_API_ENTRIES)
    }
    return response
  } catch (err) {
    const cached = await caches.match(request)
    if (cached) {
      return cached
    }
    throw err
  }
}

// ─── Prefetch thumbnails ────────────────────────────────────────────

async function prefetchThumbnails(urls) {
  const cache = await caches.open(THUMB_CACHE)

  // Only fetch URLs not already cached
  const toFetch = []
  for (const url of urls) {
    const existing = await cache.match(url)
    if (!existing) {
      toFetch.push(url)
    }
  }

  if (toFetch.length === 0) return

  // Fetch in small batches to avoid flooding the network
  const BATCH_SIZE = 6
  for (let i = 0; i < toFetch.length; i += BATCH_SIZE) {
    const batch = toFetch.slice(i, i + BATCH_SIZE)
    const results = await Promise.allSettled(
      batch.map((url) =>
        fetch(url).then((res) => {
          if (res.ok) {
            return cache.put(url, res)
          }
        }),
      ),
    )
  }

  trimCache(THUMB_CACHE, MAX_THUMB_ENTRIES)
}

// ─── LRU cache eviction ─────────────────────────────────────────────

async function trimCache(cacheName, maxEntries) {
  const cache = await caches.open(cacheName)
  const keys = await cache.keys()
  if (keys.length > maxEntries) {
    // Delete oldest entries (first in the list)
    const toDelete = keys.length - maxEntries
    for (let i = 0; i < toDelete; i++) {
      await cache.delete(keys[i])
    }
  }
}
