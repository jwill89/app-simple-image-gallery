import { defineStore } from 'pinia'
import { ref } from 'vue'

/** Optional action button rendered inside a toast (e.g. "Undo"). */
export interface ToastAction {
  label: string
  handler: () => void
}

export interface Toast {
  id: number
  title: string
  message: string
  type: 'success' | 'danger' | 'warning' | 'info'
  duration: number
  action?: ToastAction
}

const TYPE_TITLES: Record<Toast['type'], string> = {
  success: 'Success',
  danger: 'Error',
  warning: 'Warning',
  info: 'Notice',
}

let nextId = 0

/** A toast's close timer: running while `handle` is set, paused with the time it has left. */
interface CloseTimer {
  handle: ReturnType<typeof setTimeout> | undefined
  remaining: number
  startedAt: number
}

export const useToastStore = defineStore('toast', () => {
  const toasts = ref<Toast[]>([])
  const timers = new Map<number, CloseTimer>()

  function run(id: number, timer: CloseTimer) {
    timer.startedAt = Date.now()
    timer.handle = setTimeout(() => remove(id), Math.max(timer.remaining, 0))
  }

  /**
   * Shows a toast. It closes itself after `duration` ms, unless `duration` is 0 or it
   * carries an action, which stays until it is used or dismissed: an Undo that went
   * away by itself would be a time limit on undoing.
   */
  function add(
    message: string,
    type: Toast['type'] = 'danger',
    duration = 5000,
    title?: string,
    action?: ToastAction,
  ) {
    const id = nextId++
    toasts.value.push({
      id,
      title: title ?? TYPE_TITLES[type],
      message,
      type,
      duration,
      action,
    })

    if (duration > 0 && !action) {
      const timer: CloseTimer = { handle: undefined, remaining: duration, startedAt: 0 }
      timers.set(id, timer)
      run(id, timer)
    }
  }

  /** Holds a toast open, keeping the time it has left; the pointer or focus is on it. */
  function pause(id: number) {
    const timer = timers.get(id)
    if (timer?.handle === undefined) {
      return
    }
    clearTimeout(timer.handle)
    timer.handle = undefined
    timer.remaining -= Date.now() - timer.startedAt
  }

  /** Lets a paused toast close once the time it had left has passed. */
  function resume(id: number) {
    const timer = timers.get(id)
    if (timer === undefined || timer.handle !== undefined) {
      return
    }
    run(id, timer)
  }

  function remove(id: number) {
    clearTimeout(timers.get(id)?.handle)
    timers.delete(id)
    toasts.value = toasts.value.filter((t) => t.id !== id)
  }

  function success(message: string, duration = 4000, title?: string) {
    add(message, 'success', duration, title)
  }

  function error(message: string, duration = 6000, title?: string) {
    add(message, 'danger', duration, title)
  }

  function warning(message: string, duration = 5000, title?: string) {
    add(message, 'warning', duration, title)
  }

  function info(message: string, duration = 4000, title?: string) {
    add(message, 'info', duration, title)
  }

  return { toasts, add, remove, pause, resume, success, error, warning, info }
})
