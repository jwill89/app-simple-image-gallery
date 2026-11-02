<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useApi, getErrorMessage } from '../composables/useApi'
import { useFavoritesStore } from '../stores/favorites'
import { useGalleryStore } from '../stores/gallery'
import { useToastStore } from '../stores/toast'
import { endpoints } from '../api/endpoints'
import type { MediaItem } from '../types'
import GalleryCard from '../components/GalleryCard.vue'
import LoadingSpinner from '../components/LoadingSpinner.vue'
import PageHeader from '../components/PageHeader.vue'
import EmptyState from '../components/EmptyState.vue'

const router = useRouter()
const api = useApi()
const favorites = useFavoritesStore()
const store = useGalleryStore()
const toastStore = useToastStore()

const items = ref<MediaItem[]>([])
const loading = ref(false)
const loadFailed = ref(false)

const isEmpty = computed(() => favorites.count === 0)

async function loadFavorites() {
  const ids = favorites.allIds()
  if (ids.length === 0) {
    items.value = []
    return
  }

  loading.value = true
  loadFailed.value = false

  try {
    const result = await api.post<MediaItem[]>(endpoints.media.byIds, { ids })
    items.value = result ?? []

    // Prune favorites that no longer exist in the database
    const validIds = new Set(items.value.map((i) => i.media_id))
    favorites.prune(validIds)

    // Update gallery context for arrow-key navigation
    store.lastViewedItemIds = items.value.map((i) => i.media_id)
  } catch (e) {
    toastStore.error(getErrorMessage(e, 'Failed to load favorites.'), 6000, 'Load Failed')
    loadFailed.value = true
  } finally {
    loading.value = false
  }
}

function onCardClick(id: number) {
  void router.push({ name: 'media-tags', params: { id } })
}

onMounted(loadFavorites)

// Reload when favorites change (e.g. user unfavorites from this page via the card heart)
watch(() => favorites.count, loadFavorites)
</script>

<template>
  <section class="section">
    <div class="gallery-container">
      <PageHeader title="Favorites" :meta="isEmpty ? '' : `${favorites.count} saved`" />

      <!-- Was a full-width amber notification on every visit, including when
           there are no favorites to lose. Demoted to a footnote. -->
      <p class="favorites-note">
        <span class="icon is-small"><i class="fa-solid fa-circle-info" /></span>
        <span>
          Favorites live in this browser's local storage, not on the server — clearing browser data
          or switching browsers resets them.
        </span>
      </p>

      <LoadingSpinner v-if="loading && items.length === 0" />

      <EmptyState
        v-else-if="isEmpty"
        icon="fa-regular fa-heart"
        title="No favorites yet."
        hint="Click the heart on any thumbnail or media page to add it here."
      />

      <EmptyState
        v-else-if="loadFailed"
        icon="fa-solid fa-circle-exclamation"
        title="Could not load favorites."
      >
        <button type="button" class="button" @click="loadFavorites">
          <span class="icon"><i class="fa-solid fa-rotate-right" /></span>
          <span>Retry</span>
        </button>
      </EmptyState>

      <div v-else>
        <div class="media-grid">
          <GalleryCard
            v-for="item in items"
            :key="item.media_id"
            :item="item"
            @click="onCardClick"
          />
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
/* The grid lives in style.css as `.media-grid`, shared with GalleryView. */
.favorites-note {
  display: flex;
  align-items: flex-start;
  gap: var(--sp-2);
  font-size: var(--t-sm);
  color: var(--text-3);
  margin-bottom: var(--sp-4);
}
</style>
