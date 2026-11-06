/**
 * The service worker, served as /sw.js. The `gallery-service-worker` plugin in
 * vite.config.ts builds it from this file, and main.ts registers it. What it
 * caches, and how, is in cache.ts.
 */
import { isPrefetchThumbnailsMessage } from '../constants/thumbnails'
import { cacheFirst, cacheFor, prefetchThumbnails, removeOldCaches } from './cache'

declare const self: ServiceWorkerGlobalScope

self.addEventListener('install', () => {
  // Take over at once, without waiting for the old worker's tabs to close.
  void self.skipWaiting()
})

self.addEventListener('activate', (event) => {
  event.waitUntil(removeOldCaches().then(() => self.clients.claim()))
})

self.addEventListener('fetch', (event) => {
  const cacheName = cacheFor(event.request)
  if (cacheName === null) {
    return
  }
  event.respondWith(
    cacheFirst(event.request, cacheName, (work) => {
      event.waitUntil(work)
    }),
  )
})

self.addEventListener('message', (event) => {
  if (isPrefetchThumbnailsMessage(event.data)) {
    event.waitUntil(prefetchThumbnails(event.data.urls))
  }
})
