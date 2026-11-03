<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useApi, getErrorMessage, getFieldError } from '../composables/useApi'
import { useFavoritesStore } from '../stores/favorites'
import { useGalleryStore } from '../stores/gallery'
import { useToastStore } from '../stores/toast'
import { endpoints } from '../api/endpoints'
import type { MediaItem, MediaPage } from '../types'
import GalleryCard from '../components/GalleryCard.vue'
import LoadingSpinner from '../components/LoadingSpinner.vue'
import PageHeader from '../components/PageHeader.vue'
import EmptyState from '../components/EmptyState.vue'
import PaginationBar from '../components/PaginationBar.vue'
import AppDialog from '../components/AppDialog.vue'

const PER_PAGE = 60

const router = useRouter()
const api = useApi()
const favorites = useFavoritesStore()
const store = useGalleryStore()
const toastStore = useToastStore()

const items = ref<MediaItem[]>([])
const loading = ref(false)
const loadFailed = ref(false)

// ── Which list (signed in) ───────────────────────────────────────────
const selectedId = ref<number | null>(null)
const page = ref(1)
const totalPages = ref(1)
const selected = computed(
  () =>
    favorites.lists.find((l) => l.list_id === selectedId.value) ?? favorites.defaultList ?? null,
)
const isEmpty = computed(() =>
  favorites.isAccount ? (selected.value?.media_count ?? 0) === 0 : favorites.count === 0,
)
const meta = computed(() => {
  const n = favorites.isAccount ? (selected.value?.media_count ?? 0) : favorites.count
  return n === 0 ? '' : `${n.toLocaleString()} saved`
})

async function load() {
  loading.value = true
  loadFailed.value = false
  try {
    if (favorites.isAccount) await loadList()
    else await loadLocal()
    // Gallery context for the detail page's previous/next.
    store.lastViewedItemIds = items.value.map((i) => i.media_id)
  } catch (e) {
    toastStore.error(getErrorMessage(e, 'Failed to load favorites.'), 6000, 'Load Failed')
    loadFailed.value = true
  } finally {
    loading.value = false
  }
}

async function loadList() {
  const list = selected.value
  if (!list) {
    items.value = []
    return
  }
  const result = await api.get<MediaPage>(endpoints.lists.media(list.list_id, page.value, PER_PAGE))
  items.value = result?.items ?? []
  totalPages.value = result?.total_pages ?? 1
}

/** Signed out: this browser's favorites. */
async function loadLocal() {
  const ids = favorites.allIds()
  if (ids.length === 0) {
    items.value = []
    return
  }
  const result = await api.post<MediaItem[]>(endpoints.media.byIds, { ids })
  items.value = result ?? []
  // Drop favorites that no longer exist.
  favorites.prune(new Set(items.value.map((i) => i.media_id)))
}

function selectList(listId: number) {
  selectedId.value = listId
  page.value = 1
  void load()
}

function onNavigate(to: number) {
  page.value = to
  void load()
}

function onCardClick(id: number) {
  void router.push({ name: 'media-tags', params: { id } })
}

onMounted(load)
// Signed in or out while here, or the lists arrived after the page opened.
watch(
  () => [favorites.isAccount, favorites.defaultList?.list_id],
  () => void load(),
)
// A heart clicked on this page changes what it shows.
watch(
  () => favorites.count,
  () => {
    if (!favorites.isAccount || selected.value?.is_default) void load()
  },
)

// ── Managing lists ───────────────────────────────────────────────────
const nameDialog = ref<'create' | 'rename' | null>(null)
const listName = ref('')
const nameError = ref('')
const deleteOpen = ref(false)

function openCreate() {
  listName.value = ''
  nameError.value = ''
  nameDialog.value = 'create'
}

function openRename() {
  listName.value = selected.value?.name ?? ''
  nameError.value = ''
  nameDialog.value = 'rename'
}

async function saveName() {
  const name = listName.value.trim()
  try {
    if (nameDialog.value === 'create') {
      const list = await favorites.createList(name)
      if (list) selectList(list.list_id)
    } else if (selected.value) {
      await favorites.renameList(selected.value.list_id, name)
    }
    nameDialog.value = null
  } catch (e) {
    nameError.value = getFieldError(e, 'name') || getErrorMessage(e, 'Could not save the list.')
  }
}

async function deleteList() {
  const list = selected.value
  if (!list) return
  try {
    await favorites.deleteList(list.list_id)
    toastStore.success(`Deleted the list "${list.name}".`, 4000, 'Lists')
    selectedId.value = null
    page.value = 1
    void load()
  } catch (e) {
    toastStore.error(getErrorMessage(e, 'Could not delete the list.'), 6000, 'Lists')
  } finally {
    deleteOpen.value = false
  }
}
</script>

<template>
  <section class="section">
    <div class="gallery-container">
      <PageHeader
        :title="favorites.isAccount ? (selected?.name ?? 'Favorites') : 'Favorites'"
        :meta="meta"
      >
        <template v-if="favorites.isAccount" #actions>
          <button type="button" class="button is-small" @click="openCreate">
            <span class="icon"><i class="fa-solid fa-plus" /></span>
            <span>New list</span>
          </button>
          <button v-if="selected" type="button" class="button is-small" @click="openRename">
            Rename
          </button>
          <button
            v-if="selected && !selected.is_default"
            type="button"
            class="button is-small is-danger is-outlined"
            @click="deleteOpen = true"
          >
            Delete
          </button>
        </template>
      </PageHeader>

      <div
        v-if="favorites.isAccount && favorites.lists.length > 1"
        class="list-tabs"
        role="tablist"
      >
        <button
          v-for="list in favorites.lists"
          :key="list.list_id"
          type="button"
          role="tab"
          class="button is-small"
          :class="{ 'is-selected': list.list_id === selected?.list_id }"
          :aria-selected="list.list_id === selected?.list_id"
          @click="selectList(list.list_id)"
        >
          <span>{{ list.name }}</span>
          <span class="list-count">{{ list.media_count.toLocaleString() }}</span>
        </button>
      </div>

      <p v-if="!favorites.isAccount" class="favorites-note">
        <span class="icon is-small"><i class="fa-solid fa-circle-info" /></span>
        <span>
          These favorites live in this browser only.
          <router-link :to="{ name: 'register' }">Create an account</router-link> or
          <router-link :to="{ name: 'login', query: { redirect: '/favorites' } }"
            >sign in</router-link
          >
          to keep them, in lists of your own, on any device.
        </span>
      </p>

      <LoadingSpinner v-if="loading && items.length === 0" />

      <EmptyState
        v-else-if="isEmpty"
        icon="fa-regular fa-heart"
        :title="
          favorites.isAccount && !selected?.is_default ? 'This list is empty.' : 'No favorites yet.'
        "
        :hint="
          favorites.isAccount && !selected?.is_default
            ? 'Add media to it from a media page\'s list menu.'
            : 'Click the heart on any thumbnail or media page to add it here.'
        "
      />

      <EmptyState
        v-else-if="loadFailed"
        icon="fa-solid fa-circle-exclamation"
        title="Could not load favorites."
      >
        <button type="button" class="button" @click="load">
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
        <PaginationBar
          v-if="favorites.isAccount && totalPages > 1"
          :current-page="page"
          :total-pages="totalPages"
          @navigate="onNavigate"
        />
      </div>

      <AppDialog
        :open="nameDialog !== null"
        :title="nameDialog === 'create' ? 'New list' : 'Rename the list'"
        width="24rem"
        @update:open="(open) => !open && (nameDialog = null)"
      >
        <div class="field">
          <label class="label" for="list-name">Name</label>
          <input
            id="list-name"
            v-model="listName"
            class="input"
            maxlength="60"
            @keyup.enter="saveName"
          />
          <p v-if="nameError" class="help is-danger" role="alert">{{ nameError }}</p>
        </div>
        <template #footer>
          <button type="button" class="button" @click="nameDialog = null">Cancel</button>
          <button
            type="button"
            class="button is-primary"
            :disabled="listName.trim() === ''"
            @click="saveName"
          >
            Save
          </button>
        </template>
      </AppDialog>

      <AppDialog
        v-model:open="deleteOpen"
        :title="`Delete the list &quot;${selected?.name ?? ''}&quot;?`"
        destructive
      >
        <p>The list goes; the media in it stays in the gallery.</p>
        <template #footer>
          <button type="button" class="button" @click="deleteOpen = false">Cancel</button>
          <button type="button" class="button is-danger" @click="deleteList">Delete list</button>
        </template>
      </AppDialog>
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

.list-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: var(--sp-1);
  margin-bottom: var(--sp-4);
}

.list-tabs .button.is-selected {
  background: var(--surface-3);
  border-color: var(--border-strong);
}

.list-count {
  margin-left: var(--sp-2);
  color: var(--text-3);
}
</style>
