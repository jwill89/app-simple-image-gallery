import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { useApi } from '../composables/useApi'
import { endpoints } from '../api/endpoints'
import type { SavedSearch, Tag } from '../types'
import { useGalleryStore } from './gallery'

const byName = <T extends { name: string }>(a: T, b: T) => a.name.localeCompare(b.name)

/**
 * The signed-in account's blocked tags and saved searches. Blocking or
 * unblocking a tag changes what every listing shows, so it tells the gallery
 * to load again.
 */
export const useAccountStore = defineStore('account', () => {
  const api = useApi()
  const gallery = useGalleryStore()

  const blockedTags = ref<Tag[]>([])
  const searches = ref<SavedSearch[]>([])
  const blockedIds = computed(() => new Set(blockedTags.value.map((t) => t.tag_id)))

  async function load(): Promise<void> {
    const [tags, saved] = await Promise.all([
      api.get<Tag[]>(endpoints.account.blockedTags),
      api.get<SavedSearch[]>(endpoints.savedSearches.all),
    ])
    blockedTags.value = tags ?? []
    gallery.rememberTags(blockedTags.value)
    searches.value = saved ?? []
  }

  function clear(): void {
    blockedTags.value = []
    searches.value = []
  }

  async function block(tag: Tag): Promise<void> {
    await api.put(endpoints.account.blockedTag(tag.tag_id))
    if (!blockedIds.value.has(tag.tag_id)) {
      blockedTags.value = [...blockedTags.value, tag].sort((a, b) =>
        a.tag_name.localeCompare(b.tag_name),
      )
    }
    gallery.listingsChanged()
  }

  async function unblock(tagId: number): Promise<void> {
    await api.del(endpoints.account.blockedTag(tagId))
    blockedTags.value = blockedTags.value.filter((t) => t.tag_id !== tagId)
    gallery.listingsChanged()
  }

  async function saveSearch(name: string, query: string): Promise<SavedSearch | undefined> {
    const search = await api.post<SavedSearch>(endpoints.savedSearches.all, { name, query })
    if (search) {
      searches.value = [...searches.value, search].sort(byName)
    }
    return search
  }

  async function renameSearch(searchId: number, name: string): Promise<void> {
    const search = await api.patch<SavedSearch>(endpoints.savedSearches.byId(searchId), { name })
    if (search) {
      searches.value = searches.value
        .map((s) => (s.search_id === searchId ? search : s))
        .sort(byName)
    }
  }

  async function deleteSearch(searchId: number): Promise<void> {
    await api.del(endpoints.savedSearches.byId(searchId))
    searches.value = searches.value.filter((s) => s.search_id !== searchId)
  }

  return {
    blockedTags,
    blockedIds,
    searches,
    load,
    clear,
    block,
    unblock,
    saveSearch,
    renameSearch,
    deleteSearch,
  }
})
