<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import { START_LOCATION, useRouter } from 'vue-router'
import { TooltipProvider } from 'reka-ui'
import { useGalleryStore } from './stores/gallery'
import { useAuthStore } from './stores/auth'
import AppNavbar from './components/AppNavbar.vue'
import AppFooter from './components/AppFooter.vue'
import ToastContainer from './components/ToastContainer.vue'
import ErrorBoundary from './components/ErrorBoundary.vue'

const store = useGalleryStore()
const auth = useAuthStore()
const navbarHeight = ref(52)
let resizeObserver: ResizeObserver | null = null

// Pages fade from one to the next, but the first page appears as it is: there
// is no page before it to fade from, and fading it in from nothing would leave
// its text washed out (failing contrast) for its first quarter-second.
const fade = ref(false)
const stopFadeWatch = useRouter().afterEach((_to, from) => {
  fade.value = from !== START_LOCATION
})

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
})
</script>

<template>
  <!-- Every AppTooltip needs a TooltipProvider above it — without one the
       injection throws during setup and takes the whole surrounding subtree
       down with it, silently. One provider at the root covers the app and
       shares the open/close delay across every tooltip. -->
  <TooltipProvider :delay-duration="350" :skip-delay-duration="300">
    <div class="sticky-footer has-navbar-fixed-top" :style="{ paddingTop: navbarHeight + 'px' }">
      <AppNavbar />
      <main>
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
</template>
