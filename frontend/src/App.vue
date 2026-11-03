<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
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
      <ErrorBoundary>
        <router-view v-slot="{ Component }">
          <Transition name="page-fade" mode="out-in">
            <KeepAlive :include="['GalleryView']">
              <component :is="Component" />
            </KeepAlive>
          </Transition>
        </router-view>
      </ErrorBoundary>
      <AppFooter />
      <ToastContainer />
    </div>
  </TooltipProvider>
</template>
