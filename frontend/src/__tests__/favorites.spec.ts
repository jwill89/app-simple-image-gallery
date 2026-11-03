import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick } from 'vue'
import type * as UseApiModule from '../composables/useApi'
import { useFavoritesStore } from '../stores/favorites'

// Favorites signed out (this browser's local storage) and signed in (the
// account's lists on the server, faked here by mocking useApi).

const { get, post, put, del } = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  put: vi.fn(),
  del: vi.fn(),
}))

vi.mock('../composables/useApi', async (importOriginal) => {
  const actual = await importOriginal<typeof UseApiModule>()
  return {
    ...actual,
    useApi: () => ({ get, post, put, patch: vi.fn(), del, upload: vi.fn() }),
  }
})

const FAVORITES = { list_id: 1, name: 'Favorites', is_default: true, media_count: 2 }
const WALLPAPERS = { list_id: 2, name: 'Wallpapers', is_default: false, media_count: 0 }

beforeEach(() => {
  localStorage.clear()
  setActivePinia(createPinia())
  get.mockReset()
  post.mockReset()
  put.mockReset().mockResolvedValue(undefined)
  del.mockReset().mockResolvedValue(undefined)
})

describe('signed out', () => {
  it('starts empty', () => {
    const store = useFavoritesStore()
    expect(store.count).toBe(0)
    expect(store.allIds()).toEqual([])
    expect(store.isAccount).toBe(false)
  })

  it('toggles favorites on and off, without the server', async () => {
    const store = useFavoritesStore()
    await store.toggle(7)
    expect(store.isFavorite(7)).toBe(true)
    await store.toggle(7)
    expect(store.isFavorite(7)).toBe(false)
    expect(put).not.toHaveBeenCalled()
  })

  it('prunes ids that are no longer valid', async () => {
    const store = useFavoritesStore()
    for (const id of [1, 2, 3]) await store.toggle(id)
    store.prune(new Set([2, 3]))
    expect(store.allIds().sort((a, b) => a - b)).toEqual([2, 3])
  })

  it('persists to localStorage on change', async () => {
    const store = useFavoritesStore()
    await store.toggle(42)
    await nextTick()
    expect(JSON.parse(localStorage.getItem('gallery_favorites') ?? '[]')).toContain(42)
  })

  it('loads existing favorites from localStorage', () => {
    localStorage.setItem('gallery_favorites', JSON.stringify([9, 10]))
    setActivePinia(createPinia())
    const store = useFavoritesStore()
    expect(store.isFavorite(9)).toBe(true)
    expect(store.count).toBe(2)
  })

  it('ignores corrupted localStorage data', () => {
    localStorage.setItem('gallery_favorites', 'not json')
    setActivePinia(createPinia())
    const store = useFavoritesStore()
    expect(store.count).toBe(0)
  })
})

describe('signed in', () => {
  function server() {
    get.mockImplementation((url: string) => {
      if (url === '/lists') return Promise.resolve([FAVORITES, WALLPAPERS])
      if (url === '/lists/1/ids') return Promise.resolve([5, 6])
      return Promise.resolve(undefined)
    })
  }

  it("shows the account's Favorites list instead of this browser's", async () => {
    server()
    const store = useFavoritesStore()
    await store.signedIn()

    expect(store.isAccount).toBe(true)
    expect(store.defaultList?.list_id).toBe(1)
    expect(store.isFavorite(5)).toBe(true)
    expect(store.count).toBe(2)
  })

  it("moves this browser's favorites into the account, then forgets them here", async () => {
    localStorage.setItem('gallery_favorites', JSON.stringify([7, 8]))
    setActivePinia(createPinia())
    server()
    post.mockResolvedValue({ added: 2 })
    const store = useFavoritesStore()

    await store.signedIn()
    await nextTick()

    expect(post).toHaveBeenCalledWith('/lists/1/media', { media_ids: [7, 8] })
    expect(store.allIds()).toEqual([])
    expect(JSON.parse(localStorage.getItem('gallery_favorites') ?? '[]')).toEqual([])
  })

  it('the heart adds to and takes from the Favorites list on the server', async () => {
    server()
    const store = useFavoritesStore()
    await store.signedIn()

    await store.toggle(9)
    expect(put).toHaveBeenCalledWith('/lists/1/media/9')
    expect(store.isFavorite(9)).toBe(true)
    expect(store.defaultList?.media_count).toBe(3)

    await store.toggle(5)
    expect(del).toHaveBeenCalledWith('/lists/1/media/5')
    expect(store.isFavorite(5)).toBe(false)
  })

  it('puts the heart back when the server refuses', async () => {
    server()
    put.mockRejectedValue(new Error('offline'))
    const store = useFavoritesStore()
    await store.signedIn()

    await store.toggle(9)

    expect(store.isFavorite(9)).toBe(false)
  })

  it("signing out goes back to this browser's favorites", async () => {
    server()
    const store = useFavoritesStore()
    await store.signedIn()

    store.signedOut()

    expect(store.isAccount).toBe(false)
    expect(store.count).toBe(0)
    expect(store.lists).toEqual([])
  })
})
