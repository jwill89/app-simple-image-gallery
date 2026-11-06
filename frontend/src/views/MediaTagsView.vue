<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useMediaTags } from '../composables/useMediaTags'
import { useGalleryStore } from '../stores/gallery'
import { useToastStore } from '../stores/toast'
import { useApi, getErrorMessage } from '../composables/useApi'
import { useFavoritesStore } from '../stores/favorites'
import { useAuthStore } from '../stores/auth'
import { useAccountStore } from '../stores/account'
import { endpoints } from '../api/endpoints'
import type { DanbooruFetchResult, Media, Tag } from '../types'
import TagMultiSelect from '../components/TagMultiSelect.vue'
import TagBadge from '../components/TagBadge.vue'
import TagCategoryLegend from '../components/TagCategoryLegend.vue'
import LoadingSpinner from '../components/LoadingSpinner.vue'
import EmptyState from '../components/EmptyState.vue'
import AppAlert from '../components/AppAlert.vue'
import AppDialog from '../components/AppDialog.vue'
import AppLightbox from '../components/AppLightbox.vue'
import AppTooltip from '../components/AppTooltip.vue'
import AppMenu from '../components/AppMenu.vue'
import AppMenuItem from '../components/AppMenuItem.vue'

const props = defineProps<{
  mediaId: number
}>()

const router = useRouter()
const route = useRoute()
const store = useGalleryStore()
const toastStore = useToastStore()
const api = useApi()
const favorites = useFavoritesStore()
const { tags, mediaItem, loading, loadFailed, fetchMediaAndTags, addTags, removeTag } =
  useMediaTags()

const showHelpModal = ref(false)
const showDeleteModal = ref(false)
const showLightbox = ref(false)
const deleting = ref(false)
const auth = useAuthStore()
const account = useAccountStore()
// The overflow menu's actions (Danbooru import, delete) are an admin's.
const authenticated = computed(() => auth.isAdmin)
const mediaUrl = ref('')
const mediaReady = ref(false)
const selectedTagIds = ref<number[]>([])

// Danbooru fetch modal
const showDanbooruModal = ref(false)
const danbooruMode = ref<'auto' | 'post_id'>('auto')
const danbooruPostId = ref('')
const danbooruFetching = ref(false)
/** Field validation — belongs beside the input, as `.help.is-danger`. */
const postIdError = ref('')
/**
 * Import failure — stays in the dialog that caused it, so the reason is still
 * on screen while you change the lookup method and retry. The server's messages
 * here are a sentence or two (credentials, IP allowlist, rate limit), which is
 * more than a toast should carry. Success is the opposite case: the dialog has
 * done its job, so it reports through the toast like every other action.
 */
const danbooruError = ref('')

// Touch/swipe state
let touchStartX = 0
let touchStartY = 0
let touchStartTime = 0
const SWIPE_THRESHOLD = 50 // min px distance
const SWIPE_MAX_TIME = 400 // max ms for a swipe
const SWIPE_ANGLE_LIMIT = 30 // max degrees from horizontal

const appliedTagIds = computed(() => tags.value.map((t) => t.tag_id))

const mediaAltText = computed(() => `Media #${props.mediaId}`)

/**
 * Group the applied tags by category so the "Current Tags" cloud reads as
 * labeled sections (Artist / Copyright / General / Meta …) instead of one flat
 * wall. Categories are ordered by their `sort_order`; tags are alphabetised
 * within each group.
 */
const groupedTags = computed(() => {
  const sortOrderById = new Map(store.categories.map((c) => [c.category_id, c.sort_order]))
  const nameById = new Map(store.categories.map((c) => [c.category_id, c.category_name]))
  const groups = new Map<number, { name: string; sortOrder: number; tags: Tag[] }>()

  for (const tag of tags.value) {
    let group = groups.get(tag.category_id)
    if (!group) {
      group = {
        name: nameById.get(tag.category_id) ?? 'Other',
        sortOrder: sortOrderById.get(tag.category_id) ?? Number.MAX_SAFE_INTEGER,
        tags: [],
      }
      groups.set(tag.category_id, group)
    }
    group.tags.push(tag)
  }

  return [...groups.values()]
    .map((g) => ({
      ...g,
      tags: [...g.tags].sort((a, b) => a.tag_name.localeCompare(b.tag_name)),
    }))
    .sort((a, b) => a.sortOrder - b.sortOrder || a.name.localeCompare(b.name))
})

/**
 * Short enough to sit on the metadata line without truncating. The full
 * timestamp — weekday, seconds and timezone included — moves to the `title`,
 * so the precision is still there on demand rather than clipped off the edge.
 */
const formattedDate = computed(() => {
  if (!mediaItem.value?.file_time) {
    return ''
  }
  return new Date(mediaItem.value.file_time * 1000).toLocaleString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
})

const fullDate = computed(() => {
  if (!mediaItem.value?.file_time) {
    return ''
  }
  return new Date(mediaItem.value.file_time * 1000).toLocaleString(undefined, {
    weekday: 'long',
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    timeZoneName: 'short',
  })
})

const fullPath = computed(() => {
  if (!mediaItem.value) {
    return ''
  }
  return `/media/full/${mediaItem.value.file_name}`
})

const isVideoItem = computed(() => mediaItem.value?.media_type === 'video')

const dimensions = computed(() => {
  const w = mediaItem.value?.width ?? 0
  const h = mediaItem.value?.height ?? 0
  return w > 0 && h > 0 ? `${w} × ${h}` : ''
})

const formattedDuration = computed(() => {
  const secs = mediaItem.value?.duration ?? 0
  if (!secs || secs <= 0) {
    return ''
  }
  const total = Math.round(secs)
  const h = Math.floor(total / 3600)
  const m = Math.floor((total % 3600) / 60)
  const s = total % 60
  const pad = (n: number) => String(n).padStart(2, '0')
  return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${m}:${pad(s)}`
})

const formattedFileSize = computed(() => {
  const bytes = mediaItem.value?.file_size ?? 0
  if (!bytes || bytes <= 0) {
    return ''
  }
  if (bytes < 1024) {
    return `${bytes} B`
  }
  if (bytes < 1024 * 1024) {
    return `${(bytes / 1024).toFixed(1)} KB`
  }
  if (bytes < 1024 * 1024 * 1024) {
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
  }
  return `${(bytes / (1024 * 1024 * 1024)).toFixed(2)} GB`
})

onMounted(load)
watch(() => props.mediaId, load)

// ── Blocked tags ─────────────────────────────────────────────────────
// Listings leave out what the account blocks; reached directly (a link, the
// arrows), the item asks before showing.
const blockingTags = computed(() => tags.value.filter((t) => account.blockedIds.has(t.tag_id)))
const revealed = ref(false)
watch(
  () => props.mediaId,
  () => {
    revealed.value = false
  },
)
const hidden = computed(() => blockingTags.value.length > 0 && !revealed.value)

// ── Lists ────────────────────────────────────────────────────────────
// The heart is the default list; the menu beside it holds the others.
const otherLists = computed(() => favorites.lists.filter((l) => !l.is_default))
const holding = ref(new Set<number>())

async function loadHolding() {
  holding.value = new Set()
  if (!auth.isSignedIn) {
    return
  }
  try {
    holding.value = new Set((await api.get<number[]>(endpoints.lists.holding(props.mediaId))) ?? [])
  } catch {
    // Only the menu's ticks depend on it.
  }
}
watch([() => props.mediaId, () => auth.isSignedIn], loadHolding, { immediate: true })

async function toggleInList(listId: number) {
  const present = !holding.value.has(listId)
  await favorites.setInList(listId, props.mediaId, present)
  const next = new Set(holding.value)
  if (present) {
    next.add(listId)
  } else {
    next.delete(listId)
  }
  holding.value = next
}

async function load() {
  mediaUrl.value = ''
  mediaReady.value = false
  await fetchMediaAndTags(props.mediaId)
  if (mediaItem.value) {
    mediaUrl.value = `/media/full/${mediaItem.value.file_name}`
  }
}

// This page was reached via the Random action when served under the
// `/random/media/...` route; in that mode we offer a "New Random Media" jump
// instead of a Back button.
const isRandomView = computed(() => route.name === 'media-random')

function backToGallery() {
  router.back()
}

async function newRandom() {
  try {
    if (store.totalMedia === 0) {
      toastStore.info('The gallery is empty. Upload some media first.', 4000, 'No Media')
      return
    }
    const item = await api.get<Media>(endpoints.media.random)
    if (!item) {
      return
    }
    // Clear gallery context (no prev/next for random access) and stay under the
    // `/random/media/...` route so the navbar/breadcrumb keep showing Random.
    store.lastViewedItemIds = []
    void router.replace({ name: 'media-random', params: { id: item.media_id } })
  } catch {
    toastStore.error('Could not load a random media item. Please try again.', 6000, 'Random Failed')
  }
}

async function onAddTags() {
  if (selectedTagIds.value.length === 0) {
    return
  }
  await addTags(props.mediaId, [...selectedTagIds.value])
  selectedTagIds.value = []
}

async function onRemoveTag(tagId: number) {
  // Removing a tag is trivially reversible, so skip a blocking confirm and
  // offer an Undo toast instead — far less friction when cleaning up a list.
  const removed = tags.value.find((t) => t.tag_id === tagId)
  await removeTag(props.mediaId, tagId)
  if (removed) {
    toastStore.add(`Removed tag "${removed.tag_name}".`, 'info', 6000, 'Tag Removed', {
      label: 'Undo',
      handler: () => void addTags(props.mediaId, [removed.tag_id]),
    })
  }
}

async function copyHash() {
  const hash = mediaItem.value?.hash
  if (!hash) {
    return
  }
  try {
    await navigator.clipboard.writeText(hash)
    toastStore.success('MD5 hash copied to clipboard.')
  } catch {
    toastStore.error('Could not copy the hash to the clipboard.')
  }
}

function onFetchTagsClick() {
  openDanbooruModal()
}

function onDeleteClick() {
  showDeleteModal.value = true
}

const isVideo = (url: string) => {
  const ext = url.split('.').pop()?.toLowerCase()
  return ext && ['mp4', 'webm', 'mov', 'avi', 'mkv'].includes(ext)
}

const hasGalleryContext = computed(
  () => store.lastViewedItemIds.length > 0 && currentIndex.value >= 0,
)
const currentIndex = computed(() => {
  return store.lastViewedItemIds.indexOf(props.mediaId)
})
const prevId = computed(() => {
  const idx = currentIndex.value
  return idx > 0 ? store.lastViewedItemIds[idx - 1] : null
})
const nextId = computed(() => {
  const idx = currentIndex.value
  return idx >= 0 && idx < store.lastViewedItemIds.length - 1
    ? store.lastViewedItemIds[idx + 1]
    : null
})

function navigatePrev() {
  if (prevId.value != null) {
    void router.replace({ name: 'media-tags', params: { id: prevId.value } })
  }
}

function navigateNext() {
  if (nextId.value != null) {
    void router.replace({ name: 'media-tags', params: { id: nextId.value } })
  }
}

async function deleteMedia() {
  deleting.value = true
  try {
    await api.del(endpoints.media.byId(props.mediaId))
    toastStore.success('Media deleted successfully.')
    showDeleteModal.value = false

    // Remove from the gallery navigation list so arrow keys don't land on a dead item
    const idx = store.lastViewedItemIds.indexOf(props.mediaId)
    if (idx >= 0) {
      store.lastViewedItemIds.splice(idx, 1)
    }

    // Refresh totals since we removed an item
    await store.refreshTotals()

    // Navigate to the next item if available, otherwise go back
    if (nextId.value != null) {
      void router.replace({ name: 'media-tags', params: { id: nextId.value } })
    } else if (prevId.value != null) {
      void router.replace({ name: 'media-tags', params: { id: prevId.value } })
    } else {
      router.back()
    }
  } catch (e) {
    toastStore.error(getErrorMessage(e, 'Failed to delete media.'))
  } finally {
    deleting.value = false
  }
}

function openDanbooruModal() {
  danbooruMode.value = 'auto'
  danbooruPostId.value = ''
  postIdError.value = ''
  danbooruError.value = ''
  showDanbooruModal.value = true
}

async function fetchDanbooruTags() {
  danbooruFetching.value = true
  postIdError.value = ''
  danbooruError.value = ''

  try {
    const payload: Record<string, number> = {}
    if (danbooruMode.value === 'post_id') {
      const id = parseInt(danbooruPostId.value.trim(), 10)
      if (!id || id <= 0) {
        postIdError.value = 'Please enter a valid Danbooru post ID.'
        danbooruFetching.value = false
        return
      }
      payload.danbooru_post_id = id
    }

    const data = await api.post<DanbooruFetchResult>(
      endpoints.media.danbooruTags(props.mediaId),
      payload,
    )
    if (!data) {
      throw new Error('No response from Danbooru import')
    }

    tags.value = data.tags
    store.rememberTags(data.tags)
    const created = data.tags_created > 0 ? ` ${data.tags_created} newly created.` : ''
    toastStore.success(
      `Applied ${data.tags_applied} tag(s) via ${data.method}.${created}`,
      4000,
      'Danbooru Import',
    )
  } catch (e) {
    // Reported in the dialog, not as a toast — one message, in the place you
    // can act on it.
    danbooruError.value = getErrorMessage(e, 'Failed to fetch tags from Danbooru.')
  } finally {
    danbooruFetching.value = false
  }
}

// ── Keyboard navigation ────────────────────────────────────

function onGlobalKeydown(e: KeyboardEvent) {
  if (e.target instanceof HTMLInputElement || e.target instanceof HTMLTextAreaElement) {
    return
  }
  if (e.key === 'ArrowLeft') {
    navigatePrev()
  } else if (e.key === 'ArrowRight') {
    navigateNext()
  }
}

onMounted(() => {
  window.addEventListener('keydown', onGlobalKeydown)
})
onUnmounted(() => {
  window.removeEventListener('keydown', onGlobalKeydown)
})

// ── Touch/swipe navigation for mobile ──────────────────────

function onTouchStart(e: TouchEvent) {
  const touch = e.touches.item(0)
  if (e.touches.length !== 1 || !touch) {
    return
  }
  touchStartX = touch.clientX
  touchStartY = touch.clientY
  touchStartTime = Date.now()
}

function onTouchEnd(e: TouchEvent) {
  const touch = e.changedTouches.item(0)
  if (e.changedTouches.length !== 1 || !touch) {
    return
  }

  const dx = touch.clientX - touchStartX
  const dy = touch.clientY - touchStartY
  const elapsed = Date.now() - touchStartTime

  // Must be fast enough and far enough horizontally
  if (elapsed > SWIPE_MAX_TIME || Math.abs(dx) < SWIPE_THRESHOLD) {
    return
  }

  // Must be mostly horizontal (not a scroll gesture)
  const angle = Math.abs(Math.atan2(dy, dx) * (180 / Math.PI))
  if (angle > SWIPE_ANGLE_LIMIT && angle < 180 - SWIPE_ANGLE_LIMIT) {
    return
  }

  if (dx < 0) {
    navigateNext() // swipe left → next
  } else {
    navigatePrev() // swipe right → prev
  }
}
</script>

<template>
  <section class="section">
    <div class="container is-wide">
      <LoadingSpinner v-if="loading && !mediaItem" />
      <EmptyState
        v-else-if="loadFailed && !mediaItem"
        icon="fa-solid fa-circle-exclamation"
        title="Could not load media details."
      >
        <button type="button" class="button" @click="load">
          <span class="icon"><i class="fa-solid fa-rotate-right" /></span>
          <span>Retry</span>
        </button>
        <button type="button" class="button is-ghost" @click="backToGallery">
          <span class="icon"><i class="fa-solid fa-backward" /></span>
          <span>Back to Gallery</span>
        </button>
      </EmptyState>
      <template v-else>
        <div class="columns">
          <div class="column is-three-fifths">
            <AppAlert v-if="hidden" severity="warning" title="Hidden by your blocked tags">
              It's tagged
              {{ blockingTags.map((t) => t.tag_name).join(', ') }}, which you block.
              <button type="button" class="button is-small mt-2" @click="revealed = true">
                Show it anyway
              </button>
            </AppAlert>
            <figure
              v-else
              class="image tags-page-img"
              @touchstart.passive="onTouchStart"
              @touchend.passive="onTouchEnd"
            >
              <div v-if="!mediaReady" class="media-placeholder">
                <span class="icon is-large has-text-grey">
                  <i class="fa-solid fa-spinner fa-spin fa-2x" />
                </span>
              </div>
              <!-- Uploaded videos come without caption files, so there is no track to offer. -->
              <!-- eslint-disable-next-line vuejs-accessibility/media-has-caption -->
              <video
                v-if="mediaUrl && isVideo(mediaUrl)"
                controls
                :src="mediaUrl"
                :class="[
                  'media-fade',
                  { 'is-loaded': mediaReady, 'thumb-blur': store.blurThumbnails },
                ]"
                @loadeddata="mediaReady = true"
              />
              <!-- Enlarging the media is the primary verb on this page, but it
                   used to be a text link buried in the metadata table. Videos
                   keep their own controls, so only images open the lightbox. -->
              <img
                v-else-if="mediaUrl"
                :src="mediaUrl"
                :alt="mediaAltText"
                :class="[
                  'media-fade',
                  'is-zoomable',
                  { 'is-loaded': mediaReady, 'thumb-blur': store.blurThumbnails },
                ]"
                role="button"
                tabindex="0"
                :aria-label="`Enlarge ${mediaAltText}`"
                @load="mediaReady = true"
                @click="showLightbox = true"
                @keydown.enter.prevent="showLightbox = true"
                @keydown.space.prevent="showLightbox = true"
              />
            </figure>
          </div>

          <div class="column">
            <!-- Toolbar: navigation on the left, actions on the right -->
            <div class="media-toolbar">
              <div class="toolbar-nav">
                <button v-if="isRandomView" type="button" class="button" @click="newRandom">
                  <span class="icon"><i class="fa-solid fa-shuffle" /></span>
                  <span>New Random Media</span>
                </button>
                <button v-else type="button" class="button" @click="backToGallery">
                  <span class="icon"><i class="fa-solid fa-backward" /></span>
                  <span>Back</span>
                </button>
                <div v-if="hasGalleryContext" class="field has-addons mb-0">
                  <div class="control">
                    <AppTooltip label="Previous (← or swipe right)">
                      <button
                        type="button"
                        class="button"
                        :disabled="prevId == null"
                        aria-label="Previous media"
                        @click="navigatePrev"
                      >
                        <span class="icon"><i class="fa-solid fa-arrow-left" /></span>
                      </button>
                    </AppTooltip>
                  </div>
                  <div class="control">
                    <AppTooltip label="Next (→ or swipe left)">
                      <button
                        type="button"
                        class="button"
                        :disabled="nextId == null"
                        aria-label="Next media"
                        @click="navigateNext"
                      >
                        <span class="icon"><i class="fa-solid fa-arrow-right" /></span>
                      </button>
                    </AppTooltip>
                  </div>
                </div>
              </div>
              <div class="toolbar-actions">
                <button
                  type="button"
                  class="button"
                  :class="{ 'is-fav': favorites.isFavorite(mediaId) }"
                  :aria-pressed="favorites.isFavorite(mediaId)"
                  :title="
                    favorites.isFavorite(mediaId) ? 'Remove from favorites' : 'Add to favorites'
                  "
                  @click="void favorites.toggle(mediaId)"
                >
                  <span class="icon">
                    <i
                      :class="
                        favorites.isFavorite(mediaId) ? 'fa-solid fa-heart' : 'fa-regular fa-heart'
                      "
                    />
                  </span>
                  <span>{{ favorites.isFavorite(mediaId) ? 'Favorited' : 'Favorite' }}</span>
                </button>

                <AppMenu v-if="auth.isSignedIn" label="Add to a list">
                  <template #trigger><i class="fa-solid fa-list-check" /></template>
                  <AppMenuItem
                    v-for="list in otherLists"
                    :key="list.list_id"
                    :icon="
                      holding.has(list.list_id)
                        ? 'fa-solid fa-square-check'
                        : 'fa-regular fa-square'
                    "
                    @select="toggleInList(list.list_id)"
                  >
                    {{ list.name }}
                  </AppMenuItem>
                  <AppMenuItem
                    icon="fa-solid fa-folder-plus"
                    @select="void router.push({ name: 'favorites' })"
                  >
                    {{ otherLists.length === 0 ? 'Make a list…' : 'Manage lists…' }}
                  </AppMenuItem>
                </AppMenu>

                <!-- Less-used / destructive admin actions live behind an overflow
                     menu so they're not one stray click away. -->
                <AppMenu v-if="authenticated" label="More actions">
                  <AppMenuItem icon="fa-solid fa-cloud-arrow-down" @select="onFetchTagsClick">
                    Fetch Tags…
                  </AppMenuItem>
                  <AppMenuItem icon="fa-solid fa-trash" danger @select="onDeleteClick">
                    Delete Media…
                  </AppMenuItem>
                </AppMenu>
              </div>
            </div>

            <!-- Six facts don't need a bordered grid. As a table this had no
                 `.table-container`, so on a 375px screen the date lost its
                 timezone and the copy button sat outside the viewport. -->
            <dl class="media-meta">
              <div v-if="dimensions" class="media-meta-item">
                <dt>Dimensions</dt>
                <dd>{{ dimensions }}</dd>
              </div>
              <div v-if="formattedDuration" class="media-meta-item">
                <dt>Duration</dt>
                <dd>{{ formattedDuration }}</dd>
              </div>
              <div v-if="formattedFileSize" class="media-meta-item">
                <dt>Size</dt>
                <dd>{{ formattedFileSize }}</dd>
              </div>
              <div class="media-meta-item">
                <dt>Added</dt>
                <dd :title="fullDate">{{ formattedDate }}</dd>
              </div>
              <div class="media-meta-item is-hash">
                <dt>MD5</dt>
                <dd>
                  <code>{{ mediaItem?.hash }}</code>
                  <AppTooltip label="Copy MD5 hash">
                    <button
                      type="button"
                      class="button is-ghost is-small hash-copy"
                      aria-label="Copy MD5 hash"
                      @click="copyHash"
                    >
                      <span class="icon is-small"><i class="fa-regular fa-copy" /></span>
                    </button>
                  </AppTooltip>
                </dd>
              </div>
              <div class="media-meta-item">
                <dt>File</dt>
                <dd>
                  <a :href="fullPath" target="_blank"
                    >Open full {{ isVideoItem ? 'video' : 'image' }}
                    <i class="fa-solid fa-up-right-from-square fa-xs"
                  /></a>
                </dd>
              </div>
            </dl>

            <hr />

            <!-- Add Tags -->
            <h3 class="title is-6">Add Tags</h3>
            <TagMultiSelect
              v-model="selectedTagIds"
              :exclude-tag-ids="appliedTagIds"
              placeholder="Search tags to add..."
              @submit="onAddTags"
            >
              <template #actions>
                <div class="control">
                  <button
                    type="button"
                    class="button is-primary"
                    :disabled="selectedTagIds.length === 0"
                    @click="onAddTags"
                  >
                    Add Tags
                  </button>
                </div>
              </template>
            </TagMultiSelect>
            <p class="help">
              Add tags. Multiple tags are allowed.
              <a
                href="#"
                role="button"
                :aria-expanded="showHelpModal"
                @click.prevent="showHelpModal = !showHelpModal"
              >
                {{ showHelpModal ? 'Hide tag help' : 'Show tag help' }}
              </a>
              to read more about tag categories, differentiated by colors.
            </p>

            <TagCategoryLegend v-if="showHelpModal" class="mt-3" @close="showHelpModal = false" />

            <hr />

            <!-- Current Tags -->
            <h3 class="title is-6">Current Tags</h3>
            <div v-if="tags.length === 0" class="has-text-grey">No tags applied yet.</div>
            <div v-for="group in groupedTags" v-else :key="group.name" class="tag-group">
              <h4 class="tag-group-label">{{ group.name }}</h4>
              <div class="tags are-medium">
                <TagBadge
                  v-for="tag in group.tags"
                  :key="tag.tag_id"
                  :tag-id="tag.tag_id"
                  :tag-name="tag.tag_name"
                  :category-id="tag.category_id"
                  :removable="authenticated"
                  @remove="onRemoveTag"
                />
              </div>
            </div>
          </div>
        </div>
      </template>

      <!-- Full-bleed, chromeless: the image is the content, so the dialog gets
           out of its way. Escape and the backdrop both close it. -->
      <AppLightbox
        v-model:open="showLightbox"
        :src="mediaUrl"
        :alt="mediaAltText"
        :caption="dimensions"
        :href="fullPath"
      />

      <AppDialog
        :open="showDeleteModal"
        title="Delete this media item?"
        destructive
        @update:open="showDeleteModal = $event"
      >
        <p>This permanently removes the file and its tags.</p>
        <p class="has-text-danger mt-2">
          <span class="icon"><i class="fa-solid fa-triangle-exclamation" /></span>
          This action cannot be undone.
        </p>

        <template #footer>
          <button
            type="button"
            class="button is-danger"
            :class="{ 'is-loading': deleting }"
            :disabled="deleting"
            @click="deleteMedia"
          >
            <span class="icon"><i class="fa-solid fa-trash" /></span>
            <span>Delete</span>
          </button>
          <button
            type="button"
            class="button is-ghost"
            :disabled="deleting"
            @click="showDeleteModal = false"
          >
            Cancel
          </button>
        </template>
      </AppDialog>

      <AppDialog
        :open="showDanbooruModal"
        title="Fetch Danbooru Tags"
        @update:open="showDanbooruModal = $event"
      >
        <div class="field">
          <p id="danbooru-mode-label" class="label">Lookup Method</p>
          <div class="control" role="radiogroup" aria-labelledby="danbooru-mode-label">
            <label class="radio mr-4">
              <input
                v-model="danbooruMode"
                type="radio"
                value="auto"
                :disabled="danbooruFetching"
              />
              Auto (MD5 + IQDB)
            </label>
            <label class="radio">
              <input
                v-model="danbooruMode"
                type="radio"
                value="post_id"
                :disabled="danbooruFetching"
              />
              Danbooru Post ID
            </label>
          </div>
        </div>

        <div v-if="danbooruMode === 'post_id'" class="field">
          <label class="label" for="danbooru-post-id">Post ID</label>
          <div class="control">
            <input
              id="danbooru-post-id"
              v-model="danbooruPostId"
              class="input"
              type="text"
              placeholder="e.g. 1234567"
              :disabled="danbooruFetching"
              @keyup.enter="fetchDanbooruTags"
            />
          </div>
          <p v-if="postIdError" class="help is-danger">{{ postIdError }}</p>
          <p class="help">
            Enter the numeric post ID from a Danbooru URL (e.g.
            <code>danbooru.donmai.us/posts/<strong>1234567</strong></code
            >).
          </p>
        </div>

        <div v-if="danbooruMode === 'auto'" class="content">
          <p class="has-text-grey is-size-7">
            Will search Danbooru by this media's MD5 hash first. If no match is found, it will try
            IQDB visual similarity as a fallback.
          </p>
        </div>

        <AppAlert v-if="danbooruError" severity="danger" title="Import failed" class="mt-4">
          {{ danbooruError }}
        </AppAlert>

        <template #footer>
          <button
            type="button"
            class="button is-primary"
            :class="{ 'is-loading': danbooruFetching }"
            :disabled="danbooruFetching"
            @click="fetchDanbooruTags"
          >
            <span class="icon"><i class="fa-solid fa-cloud-arrow-down" /></span>
            <span>Fetch Tags</span>
          </button>
          <button
            type="button"
            class="button is-ghost"
            :disabled="danbooruFetching"
            @click="showDanbooruModal = false"
          >
            Close
          </button>
        </template>
      </AppDialog>
    </div>
  </section>
</template>

<style scoped>
.media-placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 300px;
  background: rgb(255 255 255 / 3%);
  border-radius: 6px;
}

.media-fade {
  opacity: 0;
  transition: opacity 0.3s ease;
}

.media-fade.is-loaded {
  opacity: 1;
}

.is-zoomable {
  cursor: zoom-in;
}

.is-zoomable:focus-visible {
  outline: 2px solid var(--focus);
  outline-offset: 3px;
}

/* ── Toolbar layout ───────────────────────────────────────── */

.media-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 1.25rem;
  flex-wrap: wrap;
}

.toolbar-nav {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.toolbar-actions {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

/* ── Metadata list ────────────────────────────────────────── */

.media-meta {
  display: flex;
  flex-wrap: wrap;
  gap: var(--sp-2) var(--sp-4);
  margin: 0 0 var(--sp-2);
  font-size: var(--t-sm);
}

.media-meta-item {
  display: flex;
  align-items: baseline;
  gap: var(--sp-2);
  min-width: 0;
}

.media-meta dt {
  font-family: var(--font-mono);
  font-size: var(--t-xs);
  letter-spacing: 0.09em;
  text-transform: uppercase;
  color: var(--text-3);
  flex: none;
}

.media-meta dd {
  margin: 0;
  color: var(--text-2);
  font-variant-numeric: tabular-nums;
  min-width: 0;
}

/* The hash is the one field long enough to need the whole row. */
.media-meta-item.is-hash {
  flex-basis: 100%;
}

.media-meta-item.is-hash dd {
  display: flex;
  align-items: center;
  gap: var(--sp-2);
  min-width: 0;
}

.media-meta-item.is-hash code {
  overflow-wrap: anywhere;
}

/* ── Current-tags category groups ─────────────────────────── */

.tag-group + .tag-group {
  margin-top: var(--sp-3);
}

.tag-group-label {
  font-family: var(--font-mono);
  font-size: var(--t-xs);
  font-weight: 600;
  letter-spacing: 0.11em;
  text-transform: uppercase;
  color: var(--text-3);
  margin-bottom: var(--sp-1);
}

.tag-group .tags {
  margin-bottom: 0;
}

/* The × is always visible when you can remove tags.
   Hiding it with `opacity: 0` kept it in the layout, so every chip carried a
   blank gap and read as oddly wide until you hovered — and because the reveal
   was bound to the group, hovering one tag lit up every tag in that category.
   A chip whose width doesn't match its contents is worse than a visible ×. */
.tag-group .tags :deep(.tag-badge-delete) {
  opacity: 1;
}

/* On narrow screens, let the toolbar stack but keep groups intact */
@media (width <= 480px) {
  .media-toolbar {
    justify-content: center;
  }
}
</style>
