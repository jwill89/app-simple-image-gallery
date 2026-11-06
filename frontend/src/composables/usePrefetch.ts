import type { MediaItem } from '../stores/gallery'
import { thumbnailUrl, type PrefetchThumbnailsMessage } from '../constants/thumbnails'

/**
 * Whether the cards load the @2x thumbnails. Their srcset leaves the pick to
 * the browser, so follow the pick it made for a card already on the page (the
 * class is GalleryCard's), and guess from the screen until there is one.
 */
function cardsUse2x(): boolean {
  const shown = document.querySelector<HTMLImageElement>('.gallery-card-img')?.currentSrc
  return shown ? shown.includes('@2x.') : window.devicePixelRatio > 1
}

/**
 * Ask the service worker (src/sw) to download the thumbnails the cards will
 * load for these items: only that size, since warming both doubled the
 * downloads and half of them were never shown. Silently does nothing if the
 * worker isn't running.
 */
export function prefetchThumbnails(items: MediaItem[]): void {
  if (
    !('serviceWorker' in navigator) ||
    !navigator.serviceWorker.controller ||
    items.length === 0
  ) {
    return
  }

  const size = cardsUse2x() ? '2x' : '1x'
  const message: PrefetchThumbnailsMessage = {
    type: 'PREFETCH_THUMBNAILS',
    urls: items.map((item) => thumbnailUrl(item.file_name, size)),
  }
  navigator.serviceWorker.controller.postMessage(message)
}
