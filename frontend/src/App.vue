<script setup lang="ts">
import { ref, onMounted, onUnmounted, useTemplateRef } from 'vue'
import { START_LOCATION, useRouter } from 'vue-router'
import { ConfigProvider, TooltipProvider } from 'reka-ui'
import { cspNonce } from './csp'
import { useGalleryStore } from './stores/gallery'
import { useAuthStore } from './stores/auth'
import AppNavbar from './components/AppNavbar.vue'
import AppFooter from './components/AppFooter.vue'
import ToastContainer from './components/ToastContainer.vue'
import ErrorBoundary from './components/ErrorBoundary.vue'

const store = useGalleryStore()
const auth = useAuthStore()
const navbarHeight = ref(52)
// The style elements Reka adds (a combobox's viewport) carry the page's nonce, or the
// Content-Security-Policy refuses them.
const nonce = cspNonce()
let resizeObserver: ResizeObserver | null = null

// Pages fade from one to the next, but the first page appears as it is: there
// is no page before it to fade from, and fading it in from nothing would leave
// its text washed out (failing contrast) for its first quarter-second.
const fade = ref(false)
const stopFadeWatch = useRouter().afterEach((_to, from) => {
  fade.value = from !== START_LOCATION
})

// The link that started a navigation may leave with the old page, or hide in the
// folded menu, which drops focus to <body>. A new page puts it on <main>, so Tab and
// a screen reader carry on from there. Not on the first load, where the reader
// starts at the top, nor when a page only changes its own URL (another page of the
// gallery, or infinite scroll keeping its place in the address).
const main = useTemplateRef<HTMLElement>('main')
const stopFocusOnNav = useRouter().afterEach((to, from, failure) => {
  if (!failure && from !== START_LOCATION && to.name !== from.name) {
    main.value?.focus({ preventScroll: true })
  }
})

// The skip link's own jump would add #main-content to the address, which a reload
// or a shared link would then carry; focusing <main> reaches the same place.
function skipToMain() {
  main.value?.focus()
}

onMounted(() => {
  void store.initialize()
  // Signed in from an earlier visit: who, and their lists and settings.
  void auth.restore()

  // Observe navbar height changes
  const navEl = document.querySelector('nav.navbar')
  if (navEl) {
    resizeObserver = new ResizeObserver((entries) => {
      for (const entry of entries) {
        navbarHeight.value = entry.contentRect.height
      }
    })
    resizeObserver.observe(navEl)
    navbarHeight.value = navEl.getBoundingClientRect().height
  }
})

onUnmounted(() => {
  resizeObserver?.disconnect()
  stopFadeWatch()
  stopFocusOnNav()
})
</script>

<template>
  <!-- Every AppTooltip needs a TooltipProvider above it — without one the
       injection throws during setup and takes the whole surrounding subtree
       down with it, silently. One provider at the root covers the app and
       shares the open/close delay across every tooltip. -->
  <ConfigProvider :nonce="nonce">
    <TooltipProvider :delay-duration="350" :skip-delay-duration="300">
      <div class="sticky-footer has-navbar-fixed-top" :style="{ paddingTop: navbarHeight + 'px' }">
        <a class="skip-link" href="#main-content" @click.prevent="skipToMain">Skip to content</a>
        <AppNavbar />
        <main id="main-content" ref="main" tabindex="-1">
          <ErrorBoundary>
            <router-view v-slot="{ Component }">
              <Transition name="page-fade" mode="out-in" :css="fade">
                <KeepAlive :include="['GalleryView']">
                  <component :is="Component" />
                </KeepAlive>
              </Transition>
            </router-view>
          </ErrorBoundary>
        </main>
        <AppFooter />
        <ToastContainer />
      </div>
    </TooltipProvider>
  </ConfigProvider>
</template>
