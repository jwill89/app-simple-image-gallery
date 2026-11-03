<script setup lang="ts">
import {
  ref,
  computed,
  watch,
  onMounted,
  onUnmounted,
  onActivated,
  onDeactivated,
  nextTick,
  useTemplateRef,
} from 'vue'
import { useRouter } from 'vue-router'
import { useGalleryData } from '../composables/useGalleryData'
import { useGalleryStore } from '../stores/gallery'
import { useApi } from '../composables/useApi'
import { endpoints } from '../api/endpoints'
import type { MediaItem, MediaPage, MediaFeed } from '../types'
import GalleryCard from '../components/GalleryCard.vue'
import PaginationBar from '../components/PaginationBar.vue'
import LoadingSpinner from '../components/LoadingSpinner.vue'
import PageHeader from '../components/PageHeader.vue'
import EmptyState from '../components/EmptyState.vue'
import { prefetchThumbnails } from '../composables/usePrefetch'

const props = defineProps<{
  page: number
  perPage: number
  tags?: string
}>()

// Named so App.vue can keep this view alive (preserving accumulated items and
// scroll position when returning from a media detail page).
defineOptions({ name: 'GalleryView' })

/** Build the media-listing path for a page + optional tag filter. */
function listUrl(page: number, perPage: number, tags?: string): string {
  if (tags === 'untagged') return endpoints.media.untagged(page, perPage)
  if (tags) return endpoints.media.withTags(tags, page, perPage)
  return endpoints.media.page(page, perPage)
}

const router = useRouter()
const store = useGalleryStore()
const api = useApi()
const { items, totalPages, nextCursor, loading, loadFailed, fetchPage } = useGalleryData()

const INFINITE_BATCH_SIZE = 40
// How far beyond the viewport the bottom sentinel still counts as "reached".
// Shared by the IntersectionObserver's rootMargin and the top-up loop in
// loadNextBatch, so the two can't disagree about when to fetch.
const SENTINEL_MARGIN = 400
// Viewport y (just below the fixed navbar) used to decide which card is "at the
// top" and where to park the current page after an entry-time previous-page load.
const CONTENT_TOP = 60
// Infinite scroll is a global preference (toggle in the navbar), not a URL param.
const isInfiniteScroll = computed(() => store.infiniteScroll)
const accumulatedItems = ref<MediaItem[]>([])
const loadingMore = ref(false)
const allLoaded = ref(false)
const scrollSentinel = useTemplateRef<HTMLElement>('scrollSentinel')
const topSentinel = useTemplateRef<HTMLElement>('topSentinel')
const gridEl = useTemplateRef<HTMLElement>('gridEl')
let observer: IntersectionObserver | null = null
let topObserver: IntersectionObserver | null = null
const topLoading = ref(false)
// Whether this (keep-alive'd) view is the one currently on screen.
const isActive = ref(true)
// The page whose items are at the top of the viewport while scrolling.
const currentInfinitePage = ref(1)
// The page the infinite list starts loading from, so turning infinite scroll on
// from a paged position resumes there instead of jumping back to the top.
const batchStartPage = ref(1)
// Scroll position to restore when returning from a media detail page (the view
// is kept alive, so its accumulated items — and their real heights — survive).
let savedScrollY = 0
let restoreOnActivate = false
// When entering infinite scroll partway down, the first previous-page load parks
// the entry page at the top (rather than anchoring), so the URL/view stay on it.
let bootstrapTopLoad = false
// Infinite scroll continues from the last batch's cursor rather than by page
// number, so items added while you scroll don't repeat the ones above. The next
// batch is asked for as soon as the one before it arrives: its thumbnails warm
// while you scroll, and reaching the sentinel then waits on no round trip.
let nextBatch: { url: string; data: Promise<MediaFeed | undefined> } | null = null

const displayItems = computed(() => (isInfiniteScroll.value ? accumulatedItems.value : items.value))

const headerTitle = computed(() => {
  if (!props.tags) return 'Media'
  return props.tags === 'untagged' ? 'Untagged' : props.tags.split(',').join(', ')
})

/** Position readout. In infinite mode the pager is gone, so this is the only
 *  place the current position is reported. */
const headerMeta = computed(() => {
  const total = store.totalMedia.toLocaleString()
  if (isInfiniteScroll.value) return `${displayItems.value.length.toLocaleString()} loaded`
  return props.tags
    ? `page ${props.page} of ${totalPages.value}`
    : `${total} items · page ${props.page} of ${totalPages.value}`
})

/**
 * Infinite scroll has no pager and scrolls the page header out of view, so the
 * status bar below is the only thing telling you where you are. A page number
 * is meaningless once you're scrolling continuously — position is reported as
 * an item index against the collection total instead.
 */
const topItemIndex = ref(1)

const statusPosition = computed(() => {
  const known = props.tags ? totalPages.value * props.perPage : store.totalMedia
  const total = known > 0 ? known.toLocaleString() : '?'
  return `${topItemIndex.value.toLocaleString()} of ${total}`
})

/** The grid route for a given page (infinite scroll always uses 40-item pages). */
function gridRoute(page: number) {
  return props.tags
    ? { name: 'media-with-tags', params: { page, perPage: INFINITE_BATCH_SIZE, tags: props.tags } }
    : { name: 'media', params: { page, perPage: INFINITE_BATCH_SIZE } }
}

/**
 * The 40-item batch page holding the first item of the current paged view — so a
 * paged position (which may use a different per-page size) maps to the right
 * spot in the infinite list.
 */
function startPageFromPaged() {
  return Math.floor(((props.page - 1) * props.perPage) / INFINITE_BATCH_SIZE) + 1
}

/** The infinite list's next batch: the items after `cursor`, in this listing. */
function feedUrl(cursor: string): string {
  return endpoints.media.feed({
    cursor,
    perPage: INFINITE_BATCH_SIZE,
    ...(props.tags === 'untagged' ? { untagged: true } : { tags: props.tags }),
  })
}

/** On a metered or slow connection, warming what comes next isn't worth the data. */
function onSlowConnection(): boolean {
  const conn = (
    navigator as unknown as { connection?: { saveData?: boolean; effectiveType?: string } }
  ).connection
  return conn?.saveData === true || /(^|-)2g$/.test(conn?.effectiveType ?? '')
}

/** Ask for the batch after `cursor` now, and warm its thumbnails. */
function prefetchNextBatch(cursor: string) {
  if (onSlowConnection()) return
  const url = feedUrl(cursor)
  const data = api.get<MediaFeed>(url)
  nextBatch = { url, data }
  data.then(
    (feed) => {
      if (feed?.items.length) prefetchThumbnails(feed.items)
    },
    () => {
      // Best-effort: reaching the sentinel asks again.
      if (nextBatch?.url === url) nextBatch = null
    },
  )
}

/** The batch after `cursor`: the prefetched one if it was asked for already. */
function fetchBatch(cursor: string): Promise<MediaFeed | undefined> {
  const url = feedUrl(cursor)
  const pending = nextBatch?.url === url ? nextBatch.data : undefined
  nextBatch = null
  return pending ?? api.get<MediaFeed>(url)
}

function updateStoreItemIds() {
  store.lastViewedItemIds = displayItems.value.map((i) => i.media_id)
}

// The listings' version this view last loaded: when signing in or out, or
// blocking a tag, moves it on, the kept-alive view loads again (below).
let loadedVersion = store.listingsVersion

async function loadPage() {
  loadedVersion = store.listingsVersion
  if (isInfiniteScroll.value) {
    const start = batchStartPage.value
    accumulatedItems.value = []
    allLoaded.value = false
    loadingMore.value = false
    nextBatch = null
    await fetchPage(start, INFINITE_BATCH_SIZE, props.tags)
    accumulatedItems.value = [...items.value]
    if (nextCursor.value === null) {
      allLoaded.value = true
    } else {
      // Warm the batch you're about to scroll into. Paged mode has always done
      // this; infinite scroll never did, so every batch arrived cold — which is
      // exactly where it's most noticeable, since the grid keeps moving.
      prefetchNextBatch(nextCursor.value)
    }
  } else {
    await fetchPage(props.page, props.perPage, props.tags)
    // Pre-cache thumbnails for the next page
    void prefetchAdjacentPage(props.page + 1, props.perPage, props.tags)
  }
  updateStoreItemIds()
}

async function prefetchAdjacentPage(page: number, perPage: number, tags?: string) {
  if (page < 1 || page > totalPages.value || onSlowConnection()) return

  try {
    const data = await api.get<MediaPage>(listUrl(page, perPage, tags))
    if (data?.items.length) {
      prefetchThumbnails(data.items)
    }
  } catch {
    // Prefetch is best-effort — don't disrupt the user
  }
}

async function loadNextBatch() {
  if (loadingMore.value || allLoaded.value) return
  loadingMore.value = true
  try {
    // Keep pulling batches while the sentinel is still within the trigger zone.
    //
    // IntersectionObserver only reports *transitions*. On a viewport tall enough
    // that a freshly-loaded batch doesn't push the sentinel back out of view,
    // the callback never fires a second time and loading stalls — the spinner
    // sits there forever with nothing left to scroll. Seen on a 1285px-tall
    // viewport, where 40 items across 6 columns left the document only ~500px
    // taller than the window.
    do {
      const cursor = nextCursor.value
      if (cursor === null) break
      const data = await fetchBatch(cursor)
      const newItems = data?.items ?? []
      accumulatedItems.value = [...accumulatedItems.value, ...newItems]
      nextCursor.value = data?.next_cursor ?? null
      if (nextCursor.value === null || newItems.length === 0) {
        allLoaded.value = true
      } else {
        // Stay one batch ahead of the scroll, the same way paging stays one page
        // ahead of Next. The SW LRU-caps thumbnails at 2000 entries, so this
        // can't grow without bound.
        prefetchNextBatch(nextCursor.value)
      }
      updateStoreItemIds()
      // Let the new rows lay out before re-measuring where the sentinel landed.
      await nextTick()
    } while (!allLoaded.value && sentinelInTriggerZone())
  } catch (e) {
    console.error('Failed to load more items:', e)
  } finally {
    loadingMore.value = false
  }
}

/**
 * Whether the bottom sentinel still sits inside the observer's trigger area.
 * Mirrors the `rootMargin` on `setupObserver` — if the two drift apart, the
 * top-up loop above stops matching what the observer would have done.
 */
function sentinelInTriggerZone(): boolean {
  const el = scrollSentinel.value
  if (!el) return false
  const rect = el.getBoundingClientRect()
  return rect.top < window.innerHeight + SENTINEL_MARGIN && rect.bottom > -SENTINEL_MARGIN
}

// Load the page *above* the current top (when the infinite list started partway
// down) and prepend it, anchoring the scroll so the view doesn't jump.
async function loadPrevBatch() {
  if (topLoading.value || batchStartPage.value <= 1 || accumulatedItems.value.length === 0) return
  topLoading.value = true
  const prevPage = batchStartPage.value - 1
  try {
    const data = await api.get<MediaPage>(listUrl(prevPage, INFINITE_BATCH_SIZE, props.tags))
    const prevItems = data?.items ?? []
    if (prevItems.length === 0) {
      batchStartPage.value = 1
      return
    }
    // Anchor on the current first card so prepending doesn't shift what's on screen.
    const bootstrap = bootstrapTopLoad
    bootstrapTopLoad = false
    const anchorEl = gridEl.value?.children[0] as HTMLElement | undefined
    const beforeTop = anchorEl?.getBoundingClientRect().top ?? 0
    accumulatedItems.value = [...prevItems, ...accumulatedItems.value]
    batchStartPage.value = prevPage
    await nextTick()
    if (anchorEl) {
      const nowTop = anchorEl.getBoundingClientRect().top
      // Entry: park the page we came in on at the top. Scroll-up: keep the view put.
      window.scrollBy(0, bootstrap ? nowTop - CONTENT_TOP : nowTop - beforeTop)
    }
    updateStoreItemIds()
  } catch (e) {
    console.error('Failed to load previous items:', e)
  } finally {
    topLoading.value = false
  }
}

function setupObserver() {
  observer?.disconnect()
  const el = scrollSentinel.value
  if (!el) return
  observer = new IntersectionObserver(
    (entries) => {
      if (entries[0].isIntersecting) void loadNextBatch()
    },
    { rootMargin: `${SENTINEL_MARGIN}px` },
  )
  observer.observe(el)
}

function setupTopObserver() {
  topObserver?.disconnect()
  const el = topSentinel.value
  if (!el) return
  topObserver = new IntersectionObserver(
    (entries) => {
      if (entries[0].isIntersecting) void loadPrevBatch()
    },
    // A small margin so it fires on approach but the anchored scroll then pushes
    // it clear of the viewport (no cascade of loads).
    { rootMargin: '200px' },
  )
  topObserver.observe(el)
}

watch(scrollSentinel, setupObserver)
watch(topSentinel, setupTopObserver)

// ── Infinite-scroll position → URL sync ─────────────────────
// While scrolling in infinite mode, reflect the page whose items are at the top
// of the viewport in the URL (via router.replace). The reload watch skips these
// page-only changes and scrollBehavior keeps the scroll put, so this is purely
// a position marker that also lets "turn infinite scroll off" resume the right
// page and keeps the URL honest.

let scrollRaf = 0

/** Offset of the topmost card currently visible, within the accumulated list. */
function computeTopOffset(): number {
  const grid = gridEl.value
  if (!grid || grid.children.length === 0) return 0
  const cards = grid.children
  // Cards are in document order, so `rect.bottom` increases with index — binary
  // search for the first card still visible below the navbar.
  let lo = 0
  let hi = cards.length - 1
  let first = cards.length - 1
  while (lo <= hi) {
    const mid = (lo + hi) >> 1
    if ((cards[mid] as HTMLElement).getBoundingClientRect().bottom > CONTENT_TOP) {
      first = mid
      hi = mid - 1
    } else {
      lo = mid + 1
    }
  }
  return first
}

function onScroll() {
  if (!isInfiniteScroll.value) return
  // Near the top: pull in earlier pages (the observer bootstraps the first one).
  if (window.scrollY < 300) void loadPrevBatch()

  // Everything that reads layout stays inside the animation frame. Each
  // `getBoundingClientRect()` in computeTopOffset forces a synchronous layout
  // flush, and the cards use `content-visibility: auto` — running that on every
  // scroll event pins the main thread hard enough that the sentinel's
  // IntersectionObserver never gets to fire, so the next batch never loads.
  if (scrollRaf) return
  scrollRaf = requestAnimationFrame(() => {
    scrollRaf = 0
    const offset = computeTopOffset()
    topItemIndex.value = (batchStartPage.value - 1) * INFINITE_BATCH_SIZE + offset + 1
    const page = batchStartPage.value + Math.floor(offset / INFINITE_BATCH_SIZE)
    if (page !== currentInfinitePage.value) {
      currentInfinitePage.value = page
      void router.replace(gridRoute(page))
    }
  })
}

function scrollToTop() {
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

function onKeydown(e: KeyboardEvent) {
  if (isInfiniteScroll.value || loading.value) return
  if (
    e.target instanceof HTMLInputElement ||
    e.target instanceof HTMLTextAreaElement ||
    e.target instanceof HTMLSelectElement
  )
    return

  if (e.key === 'ArrowLeft' && props.page > 1) {
    onNavigate(props.page - 1)
  } else if (e.key === 'ArrowRight' && props.page < totalPages.value) {
    onNavigate(props.page + 1)
  }
}

function addListeners() {
  // A frame queued while the view was inactive (or the tab hidden) may never
  // run, which would leave `scrollRaf` set and silently disable position
  // tracking for good. Clearing it here makes that self-healing.
  scrollRaf = 0
  window.addEventListener('keydown', onKeydown)
  window.addEventListener('scroll', onScroll, { passive: true })
}
function removeListeners() {
  if (scrollRaf) {
    cancelAnimationFrame(scrollRaf)
    scrollRaf = 0
  }
  window.removeEventListener('keydown', onKeydown)
  window.removeEventListener('scroll', onScroll)
}

onMounted(() => {
  if (isInfiniteScroll.value) {
    batchStartPage.value = startPageFromPaged()
    currentInfinitePage.value = batchStartPage.value
    bootstrapTopLoad = batchStartPage.value > 1
  }
  void loadPage()
})
onActivated(() => {
  isActive.value = true
  addListeners()
  setupObserver()
  setupTopObserver()
  // What this reader sees changed while the view was away (signed in or out,
  // blocked a tag): its page is out of date.
  if (loadedVersion !== store.listingsVersion) {
    restoreOnActivate = false
    void loadPage()
    return
  }
  // Returning from a media detail page: restore the scroll position we left at.
  if (restoreOnActivate) {
    restoreOnActivate = false
    const y = savedScrollY
    requestAnimationFrame(() => window.scrollTo(0, y))
  }
})
// Signed in or out (from the navbar) while the gallery is on screen.
watch(
  () => store.listingsVersion,
  () => {
    if (isActive.value) void loadPage()
  },
)
onDeactivated(() => {
  isActive.value = false
  removeListeners()
  observer?.disconnect()
  topObserver?.disconnect()
})
onUnmounted(() => {
  removeListeners()
  observer?.disconnect()
  topObserver?.disconnect()
})

// Reload only on genuine list changes. In infinite mode a page-only change is
// our own scroll-position URL sync (router.replace) and must not reload.
watch(
  () => [props.page, props.perPage, props.tags] as const,
  (n, o) => {
    if (isInfiniteScroll.value && n[0] !== o[0] && n[1] === o[1] && n[2] === o[2]) return
    void loadPage()
  },
)

// The Media nav asks for a fresh gallery. In paged mode the page-change watch
// already reloads; in infinite mode a page-only change is skipped, so reset here.
watch(
  () => store.galleryResetSeq,
  () => {
    if (!isActive.value || !isInfiniteScroll.value) return
    restoreOnActivate = false
    bootstrapTopLoad = false
    batchStartPage.value = 1
    currentInfinitePage.value = 1
    void loadPage()
    window.scrollTo({ top: 0 })
  },
)

// React to the navbar infinite-scroll toggle.
watch(
  () => store.infiniteScroll,
  (on) => {
    if (!isActive.value) return
    if (on) {
      // Entering infinite scroll: resume from the page we were viewing.
      batchStartPage.value = startPageFromPaged()
      currentInfinitePage.value = batchStartPage.value
      bootstrapTopLoad = batchStartPage.value > 1
      if (props.page !== batchStartPage.value || props.perPage !== INFINITE_BATCH_SIZE) {
        void router.replace(gridRoute(batchStartPage.value))
      }
    }
    // Off: props.page already reflects the position (kept in sync while
    // scrolling), so the paged load lands on the right page.
    void loadPage()
    window.scrollTo({ top: 0 })
  },
)

function onNavigate(page: number) {
  if (props.tags) {
    void router.push({
      name: 'media-with-tags',
      params: { page, perPage: props.perPage, tags: props.tags },
    })
  } else {
    void router.push({
      name: 'media',
      params: { page, perPage: props.perPage },
    })
  }
}

function onCardClick(id: number) {
  // Remember where we are so returning from the detail page restores the scroll.
  if (isInfiniteScroll.value) {
    savedScrollY = window.scrollY
    restoreOnActivate = true
  }
  void router.push({
    name: 'media-tags',
    params: { id },
  })
}
</script>

<template>
  <section class="section">
    <div class="gallery-container">
      <PageHeader :title="headerTitle" :meta="headerMeta" />

      <LoadingSpinner v-if="loading" />

      <EmptyState
        v-else-if="loadFailed || displayItems.length === 0"
        :icon="loadFailed ? 'fa-solid fa-circle-exclamation' : 'fa-solid fa-images'"
        :title="loadFailed ? 'Could not load the gallery.' : 'No items found.'"
        :hint="tags && !loadFailed ? 'Try removing a tag from the search.' : ''"
      >
        <button v-if="loadFailed" type="button" class="button" @click="loadPage">
          <span class="icon"><i class="fa-solid fa-rotate-right" /></span>
          <span>Retry</span>
        </button>
      </EmptyState>

      <div v-else>
        <!-- One pagination bar, at the bottom. The top copy plus its two rules
             cost ~170px and pushed the thumbnails below the fold — nobody
             paginates before they've looked at the page. -->
        <div class="gallery-body">
          <div
            v-if="isInfiniteScroll && batchStartPage > 1"
            ref="topSentinel"
            class="has-text-centered py-5"
          >
            <span class="icon is-large has-text-grey"
              ><i class="fa-solid fa-spinner fa-spin fa-2x"
            /></span>
          </div>
          <div ref="gridEl" class="media-grid">
            <GalleryCard
              v-for="item in displayItems"
              :key="item.media_id"
              :item="item"
              @click="onCardClick"
            />
          </div>
        </div>

        <div
          v-if="isInfiniteScroll && !allLoaded"
          ref="scrollSentinel"
          class="has-text-centered py-5"
        >
          <span class="icon is-large has-text-grey"
            ><i class="fa-solid fa-spinner fa-spin fa-2x"
          /></span>
        </div>

        <p v-if="isInfiniteScroll && allLoaded" class="has-text-centered has-text-grey py-4">
          All items loaded
        </p>

        <!-- Infinite scroll drops the pager and scrolls the page header away, so
             without this you lose both the breadcrumb and any sense of position.
             Pinned to the viewport, it carries the trail, where you are in the
             collection, and a way back. -->
        <div v-if="isInfiniteScroll" class="scroll-status" role="status" aria-live="off">
          <span class="scroll-status-trail">
            <i class="fa-solid fa-house" aria-hidden="true" />
            <span>{{ headerTitle }}</span>
          </span>
          <span class="scroll-status-pos">{{ statusPosition }}</span>
          <span class="scroll-status-loaded"
            >{{ displayItems.length.toLocaleString() }} loaded</span
          >
          <button type="button" class="scroll-status-top" title="Back to top" @click="scrollToTop">
            <i class="fa-solid fa-arrow-up" aria-hidden="true" />
            <span>Top</span>
          </button>
        </div>

        <PaginationBar
          v-if="!isInfiniteScroll"
          class="mt-4"
          :current-page="page"
          :total-pages="totalPages"
          @navigate="onNavigate"
        />
      </div>
    </div>
  </section>
</template>

<!-- The grid itself lives in style.css as `.media-grid`, shared with Favorites. -->

<style scoped>
.gallery-body {
  min-height: 75vh;
}

.scroll-status {
  position: fixed;
  left: 50%;
  bottom: var(--sp-3);
  transform: translateX(-50%);
  z-index: 25;
  display: flex;
  align-items: center;
  gap: var(--sp-3);
  max-width: calc(100vw - 2 * var(--sp-4));
  padding: var(--sp-2) var(--sp-3);
  background: var(--surface-1);
  border: 1px solid var(--border-strong);

  /* Deliberate: a floating status pill over the media wall, the only
     fully-rounded shape in the app. Everything else uses the --r-* scale. */
  border-radius: 999px; /* unslop-ignore */
  box-shadow: var(--shadow-2);
  font-size: var(--t-sm);
  color: var(--text-2);
  white-space: nowrap;
}

.scroll-status-trail {
  display: flex;
  align-items: center;
  gap: var(--sp-2);
  color: var(--text-1);
  font-weight: 550;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
}

.scroll-status-pos {
  font-family: var(--font-mono);
  font-variant-numeric: tabular-nums;
  color: var(--text-1);
}

.scroll-status-loaded {
  font-family: var(--font-mono);
  font-variant-numeric: tabular-nums;
  color: var(--text-3);
}

.scroll-status-top {
  display: flex;
  align-items: center;
  gap: var(--sp-1);
  padding: 2px var(--sp-2);
  border: 1px solid var(--border-strong);
  border-radius: 999px; /* matches the pill it sits in — unslop-ignore */
  background: transparent;
  color: var(--text-2);
  font: inherit;
  cursor: pointer;
}

.scroll-status-top:hover {
  background: var(--surface-2);
  color: var(--text-1);
}

/* On a phone the bar would eat the grid, so it keeps only what you can't get
   anywhere else: position and a way back. */
@media screen and (width <= 640px) {
  .scroll-status {
    gap: var(--sp-2);
  }

  .scroll-status-trail,
  .scroll-status-loaded {
    display: none;
  }
}
</style>
