import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick } from 'vue'
import { useGalleryStore } from '../stores/gallery'

describe('gallery store', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
  })

  it('toggles blur and persists the preference', async () => {
    const store = useGalleryStore()
    expect(store.blurThumbnails).toBe(false)

    store.toggleBlur()
    expect(store.blurThumbnails).toBe(true)
    await nextTick()
    expect(localStorage.getItem('blurThumbnails')).toBe('true')
  })

  it('reads the initial blur preference from localStorage', () => {
    localStorage.setItem('blurThumbnails', 'true')
    setActivePinia(createPinia())

    expect(useGalleryStore().blurThumbnails).toBe(true)
  })

  it('toggles infinite scroll and persists the preference', async () => {
    const store = useGalleryStore()
    expect(store.infiniteScroll).toBe(false)

    store.toggleInfiniteScroll()
    expect(store.infiniteScroll).toBe(true)
    await nextTick()
    expect(localStorage.getItem('infiniteScroll')).toBe('true')
  })

  it('reads the initial infinite-scroll preference from localStorage', () => {
    localStorage.setItem('infiniteScroll', 'true')
    setActivePinia(createPinia())

    expect(useGalleryStore().infiniteScroll).toBe(true)
  })

  it('remembers tags, and finds them by id and by name in any case', () => {
    const store = useGalleryStore()
    store.rememberTags([
      { tagId: 1, tagName: 'alpha', categoryId: 1 },
      { tagId: 2, tagName: 'Beta', categoryId: 1 },
    ])

    expect(store.tagById(1)?.tagName).toBe('alpha')
    expect(store.tagByName('beta')?.tagId).toBe(2)
    expect(store.tagByName('gamma')).toBeUndefined()
  })

  it('looks up only the tags it has not seen', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      text: async () => JSON.stringify([{ tagId: 3, tagName: 'gamma', categoryId: 1 }]),
    })
    vi.stubGlobal('fetch', fetchMock)
    const store = useGalleryStore()
    store.rememberTags([{ tagId: 1, tagName: 'alpha', categoryId: 1 }])

    await store.loadTagsById([1, 3, 3])

    expect(fetchMock).toHaveBeenCalledOnce()
    expect(fetchMock.mock.calls[0]?.[0]).toBe('/api/tags?ids=3&limit=100')
    expect(store.tagById(3)?.tagName).toBe('gamma')

    await store.loadTagsById([1, 3])
    expect(fetchMock).toHaveBeenCalledOnce()
  })

  it('asks for at most a page of tags (100) at a time', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, status: 200, text: async () => '[]' })
    vi.stubGlobal('fetch', fetchMock)

    await useGalleryStore().loadTagsByName(Array.from({ length: 150 }, (_, i) => `tag${i}`))

    const urls = fetchMock.mock.calls.map((call) => new URL(String(call[0]), 'http://x'))
    expect(urls.map((url) => url.searchParams.get('names')?.split(',').length)).toEqual([100, 50])
    expect(urls.every((url) => url.searchParams.get('limit') === '100')).toBe(true)
  })
})
