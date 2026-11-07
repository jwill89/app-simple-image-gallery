import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import router from './router'
import App from './App.vue'
import { useToastStore } from './stores/toast'
import './fontawesome' // the icon register (adds every icon the gallery uses to the library)

import 'bulma/css/bulma.min.css'
import '@fortawesome/fontawesome-svg-core/styles.css'
import './style.css'

const pinia = createPinia()
const app = createApp(App)
app.use(pinia)
app.use(router)
app.component('FontAwesomeIcon', FontAwesomeIcon)

// Global error handler — logs and shows toast notification to user
app.config.errorHandler = (err, _instance, info) => {
  console.error('Unhandled error:', err)
  console.error('Info:', info)

  try {
    const toastStore = useToastStore()
    const message = err instanceof Error ? err.message : 'An unexpected error occurred'
    toastStore.error(message)
  } catch {
    // Fallback if toast store isn't available yet
  }
}

app.mount('#app')

// Register the service worker for thumbnail caching and prefetch (src/sw,
// built to /sw.js by vite.config.ts).
//
// Production only. Registering it against the dev server means a worker that
// caches `/assets/*` cache-first sits in front of Vite, so what you see after an
// edit depends on which copy the worker hands back — and it keeps controlling
// the page across reloads, which makes local debugging unreliable in a way
// that's easy to mistake for an app bug.
if (import.meta.env.PROD && 'serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch((err: unknown) => {
      console.warn('SW registration failed:', err)
    })
  })
}
