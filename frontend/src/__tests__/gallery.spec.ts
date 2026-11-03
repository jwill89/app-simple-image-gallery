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
      { tag_id: 1, tag_name: 'alpha', category_id: 1 },
      { tag_id: 2, tag_name: 'Beta', category_id: 1 },
    ])

    expect(store.tagById(1)?.tag_name).toBe('alpha')
    expect(store.tagByName('beta')?.tag_id).toBe(2)
    expect(store.tagByName('gamma')).toBeUndefined()
  })

  it('looks up only the tags it has not seen', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      text: async () => JSON.stringify([{ tag_id: 3, tag_name: 'gamma', category_id: 1 }]),
    })
    vi.stubGlobal('fetch', fetchMock)
    const store = useGalleryStore()
    store.rememberTags([{ tag_id: 1, tag_name: 'alpha', category_id: 1 }])

    await store.loadTagsById([1, 3, 3])

    expect(fetchMock).toHaveBeenCalledOnce()
    expect(fetchMock.mock.calls[0]?.[0]).toBe('/api/tags?ids=3')
    expect(store.tagById(3)?.tag_name).toBe('gamma')

    await store.loadTagsById([1, 3])
    expect(fetchMock).toHaveBeenCalledOnce()
  })
})
