import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import type * as UseApiModule from '../composables/useApi'
import TagSearchInput from '../components/TagSearchInput.vue'

// The navbar's tag search against a fake server whose every search turns up a
// tag not seen before, as a real one may.

const { get } = vi.hoisted(() => ({ get: vi.fn() }))

vi.mock('../composables/useApi', async (importOriginal) => {
  const actual = await importOriginal<typeof UseApiModule>()
  return {
    ...actual,
    useApi: () => ({
      get,
      post: vi.fn(),
      put: vi.fn(),
      patch: vi.fn(),
      del: vi.fn(),
      upload: vi.fn(),
    }),
  }
})

const searches = () =>
  get.mock.calls.filter(([url]) => String(url).startsWith('/tags?search=')).length

let pinia: Pinia

beforeEach(() => {
  pinia = createPinia()
  setActivePinia(pinia)
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
  let found = 0
  get.mockReset()
  get.mockImplementation((url: string) => {
    if (url.startsWith('/tags?names=')) {
      return Promise.resolve([{ tag_id: 1, tag_name: 'alpha', category_id: 1 }])
    }
    if (url.startsWith('/tags?search=')) {
      found++
      return Promise.resolve([
        { tag_id: 1, tag_name: 'alpha', category_id: 1 },
        { tag_id: 100 + found, tag_name: `new ${found}`, category_id: 1 },
      ])
    }
    return Promise.resolve(undefined)
  })
})

afterEach(() => {
  vi.useRealTimers()
})

describe('TagSearchInput', () => {
  it('shows the tags named in the search, and searches once for them, not on and on', async () => {
    const wrapper = mount(TagSearchInput, {
      props: { modelValue: ['alpha'] },
      global: { plugins: [pinia] },
    })

    for (let i = 0; i < 10; i++) {
      await vi.advanceTimersByTimeAsync(200)
      await flushPromises()
    }

    expect(wrapper.text()).toContain('alpha')
    expect(searches()).toBe(1)
  })
})
