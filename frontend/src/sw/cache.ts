/**
 * The service worker's caching. sw.ts wires it to the worker's events; it
 * uses only what pages and workers share (caches, fetch, Request, Response),
 * so the app's tests can run it against a fake cache.
 *
 *   - Static assets (Vite bundles, favicon, icons): cache-first
 *   - Thumbnails (THUMBNAIL_PATH): cache-first, trimmed to the newest 2000
 *   - Everything else, the API and full-size media: left to the network
 *
 * The app posts the next page's or batch's thumbnails (a
 * PrefetchThumbnailsMessage); they download in the background, and a page
 * request for one still on its way waits for that download.
 */
import { THUMBNAIL_PATH } from '../constants/thumbnails'

// Not bumped when the media listings stopped coming through here: bumping
// renames every cache, and emptying the thumbnail cache makes the next scroll
// download every thumbnail again. removeOldCaches() drops the old listing cache
// anyway, since this version no longer names it.
const CACHE_VERSION = 'v4'
export const STATIC_CACHE = `gallery-static-${CACHE_VERSION}`
export const THUMB_CACHE = `gallery-thumbs-${CACHE_VERSION}`
type CacheName = typeof STATIC_CACHE | typeof THUMB_CACHE

interface Trim {
  max: number
  every: number
  added: number
  running: Promise<void> | null
}

// Each cache's size cap, and how many entries it takes between trims. A trim
// lists every key, and Chrome runs a cache's operations one at a time, so a
// listing holds up every read of that cache until it's done. Trimming the
// thumbnail cache after every download, as this once did, queued dozens of
// listings of 2000 keys per batch, and the thumbnails on screen waited seconds
// behind them.
const TRIM: Record<CacheName, Trim> = {
  [STATIC_CACHE]: { max: 100, every: 1, added: 0, running: null },
  [THUMB_CACHE]: { max: 2000, every: 100, added: 0, running: null },
}

// Prefetched thumbnails downloaded at once: enough to stay ahead of the scroll,
// few enough to leave room for the ones on screen.
const PREFETCH_CONCURRENCY = 6

// Downloads under way, by absolute URL. Each settles once its response is
// cached (or the download failed).
const inFlight = new Map<string, Promise<void>>()

// Thumbnails waiting to be prefetched, and how many prefetchers are taking them.
const prefetchQueue: string[] = []
let prefetchers = 0

// A transparent 1x1 GIF, for an image that is neither cached nor reachable.
const BLANK_GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'

/** The cache a request is answered from, or null to leave it to the network. */
export function cacheFor(request: Request): CacheName | null {
  const url = new URL(request.url)
  if (request.method !== 'GET' || url.origin !== location.origin) return null
  if (url.pathname.startsWith(THUMBNAIL_PATH)) return THUMB_CACHE
  if (
    url.pathname.startsWith('/assets/') ||
    url.pathname === '/favicon.png' ||
    url.pathname === '/icons.svg'
  ) {
    return STATIC_CACHE
  }
  // Everything else goes straight to the network: full-size media, and the
  // API. The media listings came through here for a while (network-first, to
  // keep an offline copy), which queued infinite scroll's next batch behind the
  // thumbnail cache's work: seconds, for a request the server answers in a
  // fraction of one.
  return null
}

/** Delete the caches this version doesn't name: an older version's, or the old listing cache. */
export async function removeOldCaches(): Promise<void> {
  const keys = await caches.keys()
  await Promise.all(keys.filter((key) => !(key in TRIM)).map((key) => caches.delete(key)))
}

/**
 * The cached copy of `request`, or else the network's, cached for next time.
 * Caching carries on after the response is handed over; `waitUntil` keeps the
 * worker alive for it.
 */
export async function cacheFirst(
  request: Request,
  cacheName: CacheName,
  waitUntil: (work: Promise<unknown>) => void,
): Promise<Response> {
  const cache = await caches.open(cacheName)
  const cached = await cache.match(request)
  if (cached) return cached

  // The prefetch may be downloading it already: wait for that download rather
  // than start a second one.
  const pending = inFlight.get(request.url)
  if (pending) {
    await pending
    const stored = await cache.match(request)
    if (stored) return stored
  }

  const response = fetch(request)
  // Cloned before the page can start reading the body, and cached in the
  // background: the page gets the response as soon as it arrives.
  waitUntil(
    download(
      request.url,
      response.then((res) => (res.ok ? store(cache, cacheName, request.url, res.clone()) : null)),
    ),
  )
  try {
    return await response
  } catch (err) {
    if (request.destination === 'image') {
      return new Response(
        Uint8Array.from(atob(BLANK_GIF), (c) => c.charCodeAt(0)),
        { headers: { 'Content-Type': 'image/gif' } },
      )
    }
    throw err
  }
}

/** Record `work`, a download into the cache, as under way for `url` until it settles. */
function download(url: string, work: Promise<unknown>): Promise<void> {
  const done: Promise<void> = work
    .then(
      () => undefined,
      () => undefined,
    )
    .finally(() => {
      if (inFlight.get(url) === done) inFlight.delete(url)
    })
  inFlight.set(url, done)
  return done
}

async function store(
  cache: Cache,
  cacheName: CacheName,
  url: string,
  response: Response,
): Promise<void> {
  await cache.put(url, response)

  // Evict old entries. Content-hashed asset filenames mean that across deploys
  // the static cache would otherwise grow without bound, since the cache name
  // (and thus the activate-time purge) never changes. Not awaited: whoever
  // waits on this download needs the entry, not the trim, and a trim cut short
  // or failed is finished by the next one.
  const trim = TRIM[cacheName]
  trim.added++
  if (!trim.running && trim.added >= trim.every) {
    trim.added = 0
    trim.running = trimCache(cacheName, trim.max)
      .catch(() => undefined)
      .finally(() => {
        trim.running = null
      })
  }
}

/** Queue `urls` for download, and start prefetchers up to the limit. */
export function prefetchThumbnails(urls: readonly string[]): Promise<void> {
  // Absolute, as the page's own requests for them are, so the two meet in `inFlight`.
  prefetchQueue.push(...urls.map((url) => new URL(url, location.origin).href))
  const started: Promise<void>[] = []
  while (prefetchers < PREFETCH_CONCURRENCY) {
    prefetchers++
    started.push(
      prefetcher().finally(() => {
        prefetchers--
      }),
    )
  }
  // When none start, the running ones take these URLs too, and the messages
  // that started them keep the worker alive until the queue is empty.
  return Promise.all(started).then(() => undefined)
}

async function prefetcher(): Promise<void> {
  const cache = await caches.open(THUMB_CACHE)
  for (let url = prefetchQueue.shift(); url !== undefined; url = prefetchQueue.shift()) {
    if (inFlight.has(url)) continue
    const cached = await cache.match(url)
    // The page may have asked for it while that looked.
    if (cached || inFlight.has(url)) continue
    await download(
      url,
      fetch(url).then((res) => (res.ok ? store(cache, THUMB_CACHE, url, res) : null)),
    )
  }
}

/** Delete the oldest entries (the first keys) past `maxEntries`. */
async function trimCache(cacheName: CacheName, maxEntries: number): Promise<void> {
  const cache = await caches.open(cacheName)
  const keys = await cache.keys()
  // One at a time, so the reads the page is waiting on can go in between.
  for (const key of keys.slice(0, Math.max(0, keys.length - maxEntries))) {
    await cache.delete(key)
  }
}
