/**
 * Cloudflare Turnstile's script, loaded once, the first time a form needs it
 * (sign-in or sign-up, while the server checks for bots). The widget itself
 * is TurnstileWidget.vue.
 */

interface TurnstileOptions {
  sitekey: string
  /** Checked by the server, so a token passes only for its own form. */
  action: string
  callback: (token: string) => void
  'expired-callback': () => void
  'error-callback': () => void
  theme?: 'auto' | 'light' | 'dark'
}

export interface TurnstileApi {
  render: (container: HTMLElement, options: TurnstileOptions) => string
  reset: (widgetId: string) => void
  remove: (widgetId: string) => void
}

declare global {
  interface Window {
    turnstile?: TurnstileApi
  }
}

const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit'

let loading: Promise<TurnstileApi> | null = null

export function loadTurnstile(): Promise<TurnstileApi> {
  loading ??= new Promise<TurnstileApi>((resolve, reject) => {
    if (window.turnstile) {
      resolve(window.turnstile)
      return
    }
    const script = document.createElement('script')
    script.src = SCRIPT_URL
    script.async = true
    script.onload = () => {
      if (window.turnstile) {
        resolve(window.turnstile)
      } else {
        reject(new Error('Turnstile did not load.'))
      }
    }
    script.onerror = () => {
      // Let the next form try again.
      loading = null
      reject(new Error('Turnstile did not load.'))
    }
    document.head.appendChild(script)
  })
  return loading
}
