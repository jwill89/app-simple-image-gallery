import { defineStore } from 'pinia'
import { ref, watch } from 'vue'
import { useApi } from '../composables/useApi'
import { endpoints } from '../api/endpoints'
import { readPreference, writePreference } from '../storage'
import { MAX_PAGE_SIZE } from '../constants/paging'
import type { Tag, TagCategory, Count } from '../types'

// Domain types are generated from the OpenAPI spec — re-export the ones used
// across the app so they can be imported from this store too.
export type { Tag, TagCategory, MediaItem } from '../types'

/** The most tags one ?ids= or ?names= lookup asks for: a page of GET /tags. */
const LOOKUP_BATCH = MAX_PAGE_SIZE

export const useGalleryStore = defineStore('gallery', () => {
  const api = useApi()

  // State
  const pageTitle = ref('Gallery')
  /**
   * The tags seen so far, by id: whatever searches and lookups returned. The
   * gallery has thousands of tags, so the app never loads them all; a picker
   * searches the server, and a chip looks its tag up here.
   */
  const knownTags = ref(new Map<number, Tag>())
  const categories = ref<TagCategory[]>([])
  const totalMedia = ref(0)
  const blurThumbnails = ref(readPreference('blurThumbnails') === 'true')
  const infiniteScroll = ref(readPreference('infiniteScroll') === 'true')
  const loading = ref(false)
  const error = ref<string | null>(null)
  const initialized = ref(false)
  const lastViewedItemIds = ref<number[]>([])
  // Bumped when the user explicitly asks for a fresh gallery (e.g. the Media
  // nav), so the kept-alive infinite-scroll view resets to the top instead of
  // restoring its previous scroll position.
  const galleryResetSeq = ref(0)
  function resetGallery() {
    galleryResetSeq.value++
  }

  // Bumped when what the listings show has changed for this reader — signing
  // in or out, blocking or unblocking a tag — so a kept-alive gallery loads
  // its page again when it comes back on screen.
  const listingsVersion = ref(0)
  function listingsChanged() {
    listingsVersion.value++
    void refreshTotals()
  }

  // Persist blur preference
  watch(blurThumbnails, (val) => {
    writePreference('blurThumbnails', String(val))
  })

  // Persist infinite-scroll preference
  watch(infiniteScroll, (val) => {
    writePreference('infiniteScroll', String(val))
  })

  // Actions
  async function initialize() {
    if (initialized.value) {
      return
    }
    initialized.value = true

    try {
      const [total, cats] = await Promise.all([
        api.get<Count>(endpoints.media.count),
        api.get<TagCategory[]>(endpoints.tagCategories.list),
      ])
      totalMedia.value = total?.count ?? 0
      categories.value = cats ?? []
    } catch (e) {
      console.error('Initialization error:', e)
      error.value = 'Failed to initialize gallery'
      initialized.value = false
    }
  }

  function rememberTags(tags: readonly Tag[]) {
    for (const tag of tags) {
      const known = knownTags.value.get(tag.tagId)
      // A tag already known as it is stays put: replacing it with an equal
      // copy would wake everything that reads the map, for nothing.
      if (known?.tagName !== tag.tagName || known.categoryId !== tag.categoryId) {
        knownTags.value.set(tag.tagId, tag)
      }
    }
  }

  function tagById(id: number): Tag | undefined {
    return knownTags.value.get(id)
  }

  /** A known tag by name, without regard to case (as tag names are compared). */
  function tagByName(name: string): Tag | undefined {
    const wanted = name.toLowerCase()
    for (const tag of knownTags.value.values()) {
      if (tag.tagName.toLowerCase() === wanted) {
        return tag
      }
    }
    return undefined
  }

  /** The best matches for a name (exact, then prefix, then containing), remembered. */
  async function searchTags(term: string, exclude: readonly number[] = [], limit = 20) {
    const tags = (await api.get<Tag[]>(endpoints.tags.list({ search: term, limit, exclude }))) ?? []
    rememberTags(tags)
    return tags
  }

  /** Look up the tags with these ids that aren't known yet. */
  async function loadTagsById(ids: readonly number[]) {
    const missing = [...new Set(ids)].filter((id) => !knownTags.value.has(id))
    for (let i = 0; i < missing.length; i += LOOKUP_BATCH) {
      rememberTags(
        (await api.get<Tag[]>(
          endpoints.tags.list({ ids: missing.slice(i, i + LOOKUP_BATCH), limit: LOOKUP_BATCH }),
        )) ?? [],
      )
    }
  }

  /** Look up the tags with these names (any case) that aren't known yet. */
  async function loadTagsByName(names: readonly string[]) {
    const missing = [...new Set(names)].filter((name) => tagByName(name) === undefined)
    for (let i = 0; i < missing.length; i += LOOKUP_BATCH) {
      rememberTags(
        (await api.get<Tag[]>(
          endpoints.tags.list({ names: missing.slice(i, i + LOOKUP_BATCH), limit: LOOKUP_BATCH }),
        )) ?? [],
      )
    }
  }

  /**
   * After tags change (renamed, recategorized, deleted) or categories do:
   * reload the categories, and the known tags as they now are.
   */
  async function refreshTags() {
    try {
      const ids = [...knownTags.value.keys()]
      const [cats, ...batches] = await Promise.all([
        api.get<TagCategory[]>(endpoints.tagCategories.list),
        ...Array.from({ length: Math.ceil(ids.length / LOOKUP_BATCH) }, (_, n) =>
          api.get<Tag[]>(
            endpoints.tags.list({
              ids: ids.slice(n * LOOKUP_BATCH, (n + 1) * LOOKUP_BATCH),
              limit: LOOKUP_BATCH,
            }),
          ),
        ),
      ])
      categories.value = cats ?? []
      knownTags.value = new Map()
      for (const batch of batches) {
        rememberTags(batch ?? [])
      }
    } catch (e) {
      console.error('Error refreshing tags:', e)
    }
  }

  async function refreshTotals() {
    try {
      const total = await api.get<Count>(endpoints.media.count)
      totalMedia.value = total?.count ?? 0
    } catch (e) {
      console.error('Error refreshing totals:', e)
    }
  }

  function toggleBlur() {
    blurThumbnails.value = !blurThumbnails.value
  }

  function toggleInfiniteScroll() {
    infiniteScroll.value = !infiniteScroll.value
  }

  return {
    pageTitle,
    knownTags,
    categories,
    totalMedia,
    blurThumbnails,
    infiniteScroll,
    loading,
    error,
    lastViewedItemIds,
    galleryResetSeq,
    listingsVersion,
    listingsChanged,
    initialize,
    rememberTags,
    tagById,
    tagByName,
    searchTags,
    loadTagsById,
    loadTagsByName,
    refreshTags,
    refreshTotals,
    toggleBlur,
    toggleInfiniteScroll,
    resetGallery,
  }
})
