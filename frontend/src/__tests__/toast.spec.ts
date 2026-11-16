import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick } from 'vue'
import { ToastRoot } from 'reka-ui'
import { useToastStore } from '../stores/toast'
import ToastContainer from '../components/ToastContainer.vue'

describe('toast store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('adds a toast with the default danger type and derived title', () => {
    const store = useToastStore()
    store.add('Something broke')

    expect(store.toasts).toHaveLength(1)
    expect(store.toasts[0]!.type).toBe('danger')
    expect(store.toasts[0]!.title).toBe('Error')
    expect(store.toasts[0]!.message).toBe('Something broke')
  })

  it('removes a toast by id', () => {
    const store = useToastStore()
    store.add('a', 'info', 0)
    const id = store.toasts[0]!.id

    store.remove(id)
    expect(store.toasts).toHaveLength(0)
  })

  it('helper methods set the right type and default title', () => {
    const store = useToastStore()
    store.success('ok', 0)
    store.warning('careful', 0)
    store.info('fyi', 0)

    expect(store.toasts.map((t) => t.type)).toEqual(['success', 'warning', 'info'])
    expect(store.toasts[0]!.title).toBe('Success')
  })

  it('uses a custom title when provided', () => {
    const store = useToastStore()
    store.add('msg', 'success', 0, 'Custom')
    expect(store.toasts[0]!.title).toBe('Custom')
  })

  it('auto-dismisses after the duration', () => {
    vi.useFakeTimers()
    const store = useToastStore()
    store.add('temp', 'info', 3000)
    expect(store.toasts).toHaveLength(1)

    vi.advanceTimersByTime(3000)
    expect(store.toasts).toHaveLength(0)
    vi.useRealTimers()
  })

  it('does not auto-dismiss when duration is 0', () => {
    vi.useFakeTimers()
    const store = useToastStore()
    store.add('sticky', 'info', 0)

    vi.advanceTimersByTime(100000)
    expect(store.toasts).toHaveLength(1)
    vi.useRealTimers()
  })

  describe('holding a toast open', () => {
    beforeEach(() => {
      vi.useFakeTimers()
    })

    afterEach(() => {
      vi.useRealTimers()
    })

    it('keeps a paused toast, and closes it once the time it had left has passed', () => {
      const store = useToastStore()
      store.add('temp', 'info', 3000)
      const id = store.toasts[0]!.id

      vi.advanceTimersByTime(1000)
      store.pause(id)
      vi.advanceTimersByTime(60_000)
      expect(store.toasts).toHaveLength(1)

      store.resume(id)
      vi.advanceTimersByTime(1999)
      expect(store.toasts).toHaveLength(1)
      vi.advanceTimersByTime(1)
      expect(store.toasts).toHaveLength(0)
    })

    it('ignores a second pause or resume', () => {
      const store = useToastStore()
      store.add('temp', 'info', 3000)
      const id = store.toasts[0]!.id

      store.resume(id)
      vi.advanceTimersByTime(1000)
      store.pause(id)
      store.pause(id)
      vi.advanceTimersByTime(5000)
      store.resume(id)
      store.resume(id)
      vi.advanceTimersByTime(2000)
      expect(store.toasts).toHaveLength(0)
    })

    it('keeps a toast with an action until it is used or dismissed', () => {
      const store = useToastStore()
      store.add('Tag removed', 'info', 4000, 'Removed', { label: 'Undo', handler: vi.fn() })
      const id = store.toasts[0]!.id

      vi.advanceTimersByTime(600_000)
      store.resume(id)
      vi.advanceTimersByTime(600_000)
      expect(store.toasts).toHaveLength(1)

      store.remove(id)
      expect(store.toasts).toHaveLength(0)
    })

    it('pauses a toast while the toast region holds the pointer or focus', async () => {
      const wrapper = mount(ToastContainer, {
        global: { stubs: { FontAwesomeIcon: true } },
        attachTo: document.body,
      })
      const store = useToastStore()
      store.add('Saved.', 'success', 3000)
      await nextTick()
      const root = wrapper.findComponent(ToastRoot)

      root.vm.$emit('pause')
      vi.advanceTimersByTime(60_000)
      expect(store.toasts).toHaveLength(1)

      root.vm.$emit('resume')
      vi.advanceTimersByTime(3000)
      expect(store.toasts).toHaveLength(0)
      wrapper.unmount()
    })
  })
})
