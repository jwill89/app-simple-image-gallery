/**
 * Where a media item's thumbnails live, and how the app asks the service worker
 * for them. Shared by the app and the worker (src/sw), which caches everything
 * under THUMBNAIL_PATH, so keep this free of DOM- and worker-only APIs.
 */

export const THUMBNAIL_PATH = '/media/thumbs/'

/** The file's name without its extension, which names its thumbnails. */
export function thumbnailBaseName(fileName: string): string {
  return fileName.split('.').slice(0, -1).join('.')
}

/** A media item's WebP thumbnail, at 1x or 2x. */
export function thumbnailUrl(fileName: string, size: '1x' | '2x' = '1x'): string {
  return `${THUMBNAIL_PATH}${thumbnailBaseName(fileName)}${size === '2x' ? '@2x' : ''}.webp`
}

/** Asks the service worker to download these thumbnails ahead of the page. */
export interface PrefetchThumbnailsMessage {
  type: 'PREFETCH_THUMBNAILS'
  urls: string[]
}

/** Whether a message posted to the worker is a prefetch with something in it. */
export function isPrefetchThumbnailsMessage(data: unknown): data is PrefetchThumbnailsMessage {
  if (typeof data !== 'object' || data === null) return false
  const { type, urls } = data as Partial<Record<keyof PrefetchThumbnailsMessage, unknown>>
  return (
    type === 'PREFETCH_THUMBNAILS' &&
    Array.isArray(urls) &&
    urls.length > 0 &&
    urls.every((url) => typeof url === 'string')
  )
}
