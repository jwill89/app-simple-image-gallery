import { describe, expect, it } from 'vitest'
import { isPrefetchThumbnailsMessage, thumbnailUrl } from '../constants/thumbnails'

describe('thumbnailUrl', () => {
  it('names the WebP thumbnail after the file, without its extension', () => {
    expect(thumbnailUrl('cat.and.dog.png')).toBe('/media/thumbs/cat.and.dog.webp')
    expect(thumbnailUrl('clip.mp4', '2x')).toBe('/media/thumbs/clip@2x.webp')
  })
})

describe('isPrefetchThumbnailsMessage', () => {
  it('accepts a prefetch with URLs, and nothing else', () => {
    expect(isPrefetchThumbnailsMessage({ type: 'PREFETCH_THUMBNAILS', urls: ['/a'] })).toBe(true)
    expect(isPrefetchThumbnailsMessage({ type: 'PREFETCH_THUMBNAILS', urls: [] })).toBe(false)
    expect(isPrefetchThumbnailsMessage({ type: 'PREFETCH_THUMBNAILS', urls: [1] })).toBe(false)
    expect(isPrefetchThumbnailsMessage({ type: 'OTHER', urls: ['/a'] })).toBe(false)
    expect(isPrefetchThumbnailsMessage(null)).toBe(false)
    expect(isPrefetchThumbnailsMessage('PREFETCH_THUMBNAILS')).toBe(false)
  })
})
