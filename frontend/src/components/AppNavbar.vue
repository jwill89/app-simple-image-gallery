<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useGalleryStore } from '../stores/gallery'
import { useApi, getErrorMessage, getFieldError } from '../composables/useApi'
import { useFavoritesStore } from '../stores/favorites'
import { useToastStore } from '../stores/toast'
import { useAuthStore } from '../stores/auth'
import { useAccountStore } from '../stores/account'
import { endpoints } from '../api/endpoints'
import type { Media, SavedSearch } from '../types'
import { ToolbarRoot, ToolbarButton, Toggle } from 'reka-ui'
import TagSearchInput from './TagSearchInput.vue'
import AppMenu from './AppMenu.vue'
import AppMenuItem from './AppMenuItem.vue'
import AppTooltip from './AppTooltip.vue'
import AppDialog from './AppDialog.vue'

const router = useRouter()
const route = useRoute()
const store = useGalleryStore()
const api = useApi()
const favorites = useFavoritesStore()
const toastStore = useToastStore()
const auth = useAuthStore()
const account = useAccountStore()

const burgerActive = ref(false)
const selectedTags = ref<string[]>([])
const perPage = ref(40)

// The bar is opaque, so nothing shows through it — but with infinite scroll the
// media passes underneath continuously, and a flat 1px border isn't enough
// separation at the moment a bright thumbnail slides under. Elevation is added
// only once there's something to sit above.
const scrolled = ref(false)
function onWindowScroll() {
  scrolled.value = window.scrollY > 4
}
onMounted(() => {
  window.addEventListener('scroll', onWindowScroll, { passive: true })
  onWindowScroll()
})
onUnmounted(() => window.removeEventListener('scroll', onWindowScroll))

// A media detail page reached via Random lives under the `/random/media/...`
// route (name `media-random`), so it counts as the Random destination.
const isRandomActive = computed(() => route.name === 'media-random')
const isMediaActive = computed(() => {
  const name = route.name as string
  return name === 'media' || name === 'media-with-tags' || name === 'media-tags'
})
const isTagsActive = computed(() => {
  const name = route.name as string
  return (
    name === 'tags' ||
    name === 'tag-categories' ||
    name === 'danbooru-rules' ||
    name === 'tag-implications'
  )
})
const isFavoritesActive = computed(() => route.name === 'favorites')
const isLoginActive = computed(() => route.name === 'login')
// Upload and Duplicates no longer have their own nav items — they live in the
// account menu, so there's nothing to mark as selected.

// Declared per route rather than derived from a chain of negations — that chain
// is why the per-page select and infinite-scroll toggle rendered, live and
// clickable, on /favorites (which has no pagination) and on the 404 page.
const showBlurToggle = computed(() => route.meta.showBlur === true)
const showViewMode = computed(() => route.meta.showViewMode === true)

/**
 * Items-per-page and infinite scroll were two controls expressing one decision,
 * so they're one control now. `0` is the continuous sentinel in the select only
 * — the store still holds the real boolean.
 */
const blurPressed = computed({
  get: () => store.blurThumbnails,
  set: () => store.toggleBlur(),
})

const CONTINUOUS = 0
const viewMode = computed({
  get: () => (store.infiniteScroll ? CONTINUOUS : perPage.value),
  set: (value: number) => {
    const wantContinuous = value === CONTINUOUS
    if (wantContinuous !== store.infiniteScroll) store.toggleInfiniteScroll()
    if (!wantContinuous) {
      perPage.value = value
      onPerPageChange()
    }
  },
})

// Real hrefs make the nav links focusable and openable in a new tab. A plain
// click still goes through the handlers below, which also reset navbar state.
const mediaHref = computed(
  () => router.resolve({ name: 'media', params: { page: 1, perPage: perPage.value } }).href,
)
const tagsHref = router.resolve({ name: 'tags' }).href
const favoritesHref = router.resolve({ name: 'favorites' }).href

function navigateMedia() {
  selectedTags.value = []
  // Force a fresh top-of-list load even if the kept-alive gallery is already
  // mounted at a scrolled infinite-scroll position.
  store.resetGallery()
  void router.push({ name: 'media', params: { page: 1, perPage: perPage.value } })
  burgerActive.value = false
}

function navigateTags() {
  selectedTags.value = []
  void router.push({ name: 'tags' })
  burgerActive.value = false
}

function navigateFavorites() {
  selectedTags.value = []
  void router.push({ name: 'favorites' })
  burgerActive.value = false
}

function navigateUpload() {
  selectedTags.value = []
  void router.push({ name: 'upload' })
  burgerActive.value = false
}

function navigateDupes() {
  selectedTags.value = []
  void router.push({ name: 'duplicates' })
  burgerActive.value = false
}

async function navigateRandom() {
  burgerActive.value = false
  try {
    if (store.totalMedia === 0) {
      toastStore.info('The gallery is empty. Upload some media first.', 4000, 'No Media')
      return
    }
    const item = await api.get<Media>(endpoints.media.random)
    if (!item) return

    // Clear gallery context so arrow keys are disabled for random access
    store.lastViewedItemIds = []
    void router.push({ name: 'media-random', params: { id: item.media_id } })
  } catch {
    toastStore.error('Could not load a random media item. Please try again.', 6000, 'Random Failed')
  }
}

function navigateLogin() {
  // Back here once signed in, unless "here" is the sign-in or sign-up page.
  const redirect = route.name === 'login' || route.name === 'register' ? undefined : route.fullPath
  void router.push({ name: 'login', query: redirect ? { redirect } : {} })
  burgerActive.value = false
}

function navigateTo(name: 'account' | 'users') {
  selectedTags.value = []
  void router.push({ name })
  burgerActive.value = false
}

async function signOut() {
  burgerActive.value = false
  await auth.signOut()
  toastStore.info('You are signed out.', 3000, 'Signed out')
}

// ── Saved searches ───────────────────────────────────────────────────
/** The search on screen, as a saved search keeps it ("cat,-dog", or "untagged"). */
const currentQuery = computed(() =>
  route.name === 'media-with-tags' && typeof route.params.tags === 'string'
    ? route.params.tags
    : '',
)
const showSearches = computed(
  () => auth.isSignedIn && (account.searches.length > 0 || currentQuery.value !== ''),
)
const saveOpen = ref(false)
const saveName = ref('')
const saveError = ref('')

function runSearch(search: SavedSearch) {
  void router.push({
    name: 'media-with-tags',
    params: { page: 1, perPage: perPage.value, tags: search.query },
  })
}

function openSaveSearch() {
  saveName.value = currentQuery.value === 'untagged' ? 'Untagged' : currentQuery.value
  saveError.value = ''
  saveOpen.value = true
}

async function saveSearch() {
  try {
    await account.saveSearch(saveName.value.trim(), currentQuery.value)
    saveOpen.value = false
    toastStore.success(`Saved the search "${saveName.value.trim()}".`, 4000, 'Saved searches')
  } catch (e) {
    saveError.value = getFieldError(e, 'name') || getErrorMessage(e, 'Could not save the search.')
  }
}

function searchWithTags() {
  if (selectedTags.value.length === 0) return
  void router.push({
    name: 'media-with-tags',
    params: { page: 1, perPage: perPage.value, tags: selectedTags.value.join(',') },
  })
}

function resetSearch() {
  selectedTags.value = []
  void router.push({ name: 'media', params: { page: 1, perPage: perPage.value } })
}

function searchUntagged() {
  selectedTags.value = []
  void router.push({
    name: 'media-with-tags',
    params: { page: 1, perPage: perPage.value, tags: 'untagged' },
  })
  burgerActive.value = false
}

const isUntaggedActive = computed(() => {
  return route.params.tags === 'untagged'
})

function onPerPageChange() {
  if (isUntaggedActive.value) {
    void router.push({
      name: 'media-with-tags',
      params: { page: 1, perPage: perPage.value, tags: 'untagged' },
    })
  } else if (selectedTags.value.length > 0) {
    void router.push({
      name: 'media-with-tags',
      params: { page: 1, perPage: perPage.value, tags: selectedTags.value.join(',') },
    })
  } else {
    void router.push({ name: 'media', params: { page: 1, perPage: perPage.value } })
  }
}

// Sync auth state and route params on navigation
router.afterEach((to) => {
  if (to.params.tags && to.params.tags !== 'untagged') {
    const tagsParam = to.params.tags as string
    selectedTags.value = tagsParam
      .split(',')
      .map((t) => t.trim())
      .filter(Boolean)
  } else if (!to.name?.toString().includes('with-tags') || to.params.tags === 'untagged') {
    selectedTags.value = []
  }
  if (to.params.perPage !== '') {
    const pp = Number(to.params.perPage)
    // `0` in the URL is a stale value from when it meant infinite scroll; the
    // mode now lives in the store, and `0` only exists as the select's sentinel.
    // Falling back to 40 keeps the select from rendering blank.
    perPage.value = isNaN(pp) || pp < 1 ? 40 : pp
  }
})
</script>

<template>
  <nav
    class="navbar is-fixed-top"
    :class="{ 'is-scrolled': scrolled }"
    role="navigation"
    aria-label="main-menu"
  >
    <div class="navbar-brand">
      <button
        type="button"
        class="navbar-burger"
        :class="{ 'is-active': burgerActive }"
        aria-label="menu"
        :aria-expanded="burgerActive"
        @click="burgerActive = !burgerActive"
      >
        <span aria-hidden="true" />
        <span aria-hidden="true" />
        <span aria-hidden="true" />
        <span aria-hidden="true" />
      </button>
    </div>

    <!-- Only the destination links collapse into the burger. -->
    <div class="navbar-menu" :class="{ 'is-active': burgerActive }">
      <div class="navbar-start">
        <a
          :href="mediaHref"
          class="navbar-item"
          :class="{ 'is-selected': isMediaActive }"
          @click.prevent="navigateMedia"
        >
          <span class="icon"><i class="fa-solid fa-images" /></span>
          <span>Media</span>
        </a>

        <!-- An action, not a destination: there's no URL to link to until the
             random item has been fetched. -->
        <a
          role="button"
          tabindex="0"
          class="navbar-item"
          :class="{ 'is-selected': isRandomActive }"
          @click="navigateRandom"
          @keydown.enter="navigateRandom"
          @keydown.space.prevent="navigateRandom"
        >
          <span class="icon"><i class="fa-solid fa-shuffle" /></span>
          <span>Random</span>
        </a>

        <a
          :href="tagsHref"
          class="navbar-item"
          :class="{ 'is-selected': isTagsActive }"
          @click.prevent="navigateTags"
        >
          <span class="icon"><i class="fa-solid fa-tags" /></span>
          <span>Tags</span>
        </a>

        <a
          :href="favoritesHref"
          class="navbar-item"
          :class="{ 'is-selected': isFavoritesActive }"
          @click.prevent="navigateFavorites"
        >
          <span class="icon"><i class="fa-solid fa-heart" /></span>
          <span>Favorites</span>
          <span v-if="favorites.count > 0" class="tag is-rounded is-small ml-1">{{
            favorites.count
          }}</span>
        </a>
      </div>
    </div>

    <!-- Deliberately a sibling of `.navbar-menu`, not a child: Bulma hides the
         menu below 1024px, which is how search — the primary tool for a
         5,000-item library — ended up two taps deep inside the burger.
         Roving tabindex makes the whole cluster one tab stop. -->
    <ToolbarRoot class="nav-toolbar" aria-label="View and search controls">
      <div class="navbar-item nav-search">
        <div class="field has-addons">
          <div class="control">
            <TagSearchInput v-model="selectedTags" @search="searchWithTags" @reset="resetSearch" />
          </div>
          <div class="control">
            <AppTooltip label="Show only untagged media">
              <ToolbarButton
                class="button"
                :class="{ 'is-warning': isUntaggedActive }"
                aria-label="Show untagged media"
                :aria-pressed="isUntaggedActive"
                @click="searchUntagged"
              >
                <span class="icon"><i class="fa-solid fa-ban" /></span>
              </ToolbarButton>
            </AppTooltip>
          </div>
        </div>
      </div>

      <div v-if="showBlurToggle" class="navbar-item">
        <AppTooltip :label="store.blurThumbnails ? 'Unblur thumbnails' : 'Blur thumbnails'">
          <!-- Toggle, not Switch: this is a toolbar button that stays
                 pressed (`aria-pressed`), not a settings-form control. It stays
                 in the bar at every width — it's the one control you reach for
                 in a hurry. -->
          <Toggle v-model="blurPressed" class="button nav-toggle" aria-label="Blur thumbnails">
            <span class="icon">
              <i :class="store.blurThumbnails ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye'" />
            </span>
            <span class="nav-toggle-label">Blur</span>
          </Toggle>
        </AppTooltip>
      </div>

      <div v-if="showViewMode" class="navbar-item nav-viewmode">
        <div class="select">
          <select v-model.number="viewMode" aria-label="How many items to show">
            <option :value="15">15 per page</option>
            <option :value="30">30 per page</option>
            <option :value="40">40 per page</option>
            <option :value="60">60 per page</option>
            <option :value="100">100 per page</option>
            <option :value="0">Continuous scroll</option>
          </select>
        </div>
      </div>

      <div v-if="showSearches" class="navbar-item">
        <AppMenu label="Saved searches">
          <template #trigger><i class="fa-solid fa-bookmark" /></template>
          <AppMenuItem
            v-for="search in account.searches"
            :key="search.search_id"
            icon="fa-solid fa-magnifying-glass"
            @select="runSearch(search)"
          >
            {{ search.name }}
          </AppMenuItem>
          <AppMenuItem v-if="currentQuery" icon="fa-regular fa-bookmark" @select="openSaveSearch">
            Save this search…
          </AppMenuItem>
        </AppMenu>
      </div>

      <div class="navbar-item">
        <!-- The account's destinations and sign-out live behind one menu, so
               the nav doesn't change shape when you sign in and Sign out isn't
               the loudest thing on screen. -->
        <AppMenu v-if="auth.isSignedIn" :label="`Signed in as ${auth.user?.username ?? ''}`">
          <template #trigger><i class="fa-solid fa-user" /></template>
          <AppMenuItem icon="fa-solid fa-user-gear" @select="navigateTo('account')">
            Account
          </AppMenuItem>
          <template v-if="auth.isAdmin">
            <AppMenuItem icon="fa-solid fa-cloud-arrow-up" @select="navigateUpload">
              Upload
            </AppMenuItem>
            <AppMenuItem icon="fa-solid fa-clone" @select="navigateDupes">Duplicates</AppMenuItem>
            <AppMenuItem icon="fa-solid fa-users" @select="navigateTo('users')">Users</AppMenuItem>
          </template>
          <AppMenuItem icon="fa-solid fa-right-from-bracket" @select="signOut">
            Sign out
          </AppMenuItem>
        </AppMenu>
        <ToolbarButton
          v-else
          class="button"
          :class="{ 'is-selected': isLoginActive }"
          @click="navigateLogin"
        >
          <span class="icon"><i class="fa-solid fa-right-to-bracket" /></span>
          <span class="nav-login-label">Sign in</span>
        </ToolbarButton>
      </div>
    </ToolbarRoot>

    <AppDialog v-model:open="saveOpen" title="Save this search" width="24rem">
      <div class="field">
        <label class="label" for="save-search-name">Name</label>
        <input
          id="save-search-name"
          v-model="saveName"
          class="input"
          maxlength="60"
          @keyup.enter="saveSearch"
        />
        <p class="help">Searches for: {{ currentQuery }}</p>
        <p v-if="saveError" class="help is-danger" role="alert">{{ saveError }}</p>
      </div>
      <template #footer>
        <button type="button" class="button" @click="saveOpen = false">Cancel</button>
        <button
          type="button"
          class="button is-primary"
          :disabled="saveName.trim() === ''"
          @click="saveSearch"
        >
          Save
        </button>
      </template>
    </AppDialog>
  </nav>
</template>

<style scoped>
.nav-toolbar {
  display: flex;
  align-items: center;
  gap: var(--sp-1);
  margin-left: auto;
  padding-right: var(--sp-2);
  min-width: 0;
}

.nav-toolbar .navbar-item {
  padding: 0;
}

.nav-search {
  min-width: 0;
  flex: 1 1 auto;
}

.nav-toggle {
  display: inline-flex;
  align-items: center;
  gap: var(--sp-2);
}

/* Keyed off `aria-pressed`, not `data-state`: AppTooltip wraps this with
   `as-child`, so TooltipTrigger merges its own `data-state` (open/closed) onto
   the same element and clobbers Toggle's on/off. `aria-pressed` is untouched
   and is the accessible source of truth anyway. */
.nav-toggle[aria-pressed='true'] {
  background: var(--surface-3);
  border-color: var(--border-strong);
  color: var(--text-1);
}

/* Below Bulma's navbar breakpoint the labels go but the controls stay —
   search and blur are the two you reach for constantly. */
@media screen and (width <= 1023px) {
  .nav-toolbar {
    flex: 1 1 auto;
    padding-right: var(--sp-1);
  }

  .nav-toggle-label,
  .nav-login-label {
    display: none;
  }

  .nav-viewmode {
    display: none;
  }
}
</style>
