import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick } from 'vue'
import { readPreference, writePreference } from '../storage'
import { useGalleryStore } from '../stores/gallery'

/** Makes every read or write of storage throw, as a private window can. */
function blockStorage(...methods: ('getItem' | 'setItem' | 'removeItem')[]) {
  for (const method of methods) {
    vi.spyOn(Storage.prototype, method).mockImplementation(() => {
      throw new DOMException('blocked', 'SecurityError')
    })
  }
}

describe('preferences in storage', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('keeps, reads and forgets a value', () => {
    writePreference('pick', 'yes')
    expect(readPreference('pick')).toBe('yes')

    writePreference('pick', null)
    expect(readPreference('pick')).toBeNull()
    expect(localStorage.getItem('pick')).toBeNull()
  })

  it('reads nothing when storage is blocked', () => {
    localStorage.setItem('pick', 'yes')
    blockStorage('getItem')

    expect(readPreference('pick')).toBeNull()
  })

  it('drops a write when storage is blocked', () => {
    blockStorage('setItem', 'removeItem')

    expect(() => {
      writePreference('pick', 'yes')
      writePreference('pick', null)
    }).not.toThrow()
  })

  it('lets the gallery start and keep its preferences in memory when storage is blocked', async () => {
    blockStorage('getItem', 'setItem')

    const store = useGalleryStore()
    expect(store.blurThumbnails).toBe(false)

    store.toggleBlur()
    await nextTick()
    expect(store.blurThumbnails).toBe(true)
  })
})
