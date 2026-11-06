import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useToastStore } from '../stores/toast'

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
})
