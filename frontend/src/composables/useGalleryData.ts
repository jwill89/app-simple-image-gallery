import { ref } from 'vue'
import { useApi, getErrorMessage } from './useApi'
import { useToastStore } from '../stores/toast'
import { endpoints } from '../api/endpoints'
import type { Media, MediaPage } from '../types'

export function useGalleryData() {
  const api = useApi()
  const toastStore = useToastStore()
  const items = ref<Media[]>([])
  const totalPages = ref(0)
  /** Where GET /media continues after this page; null when nothing follows. */
  const nextCursor = ref<string | null>(null)
  const loading = ref(false)
  const loadFailed = ref(false)

  async function fetchPage(page: number, perPage: number, tags?: string) {
    loading.value = true
    loadFailed.value = false

    try {
      const url =
        tags === 'untagged'
          ? endpoints.media.untagged(page, perPage)
          : tags
            ? endpoints.media.withTags(tags, page, perPage)
            : endpoints.media.page(page, perPage)

      const data = await api.get<MediaPage>(url)

      items.value = data?.items ?? []
      totalPages.value = data?.total_pages ?? 1
      nextCursor.value = data?.next_cursor ?? null
    } catch (e) {
      toastStore.error(getErrorMessage(e, 'Failed to load gallery'))
      loadFailed.value = true
      items.value = []
      totalPages.value = 0
      nextCursor.value = null
    } finally {
      loading.value = false
    }
  }

  return { items, totalPages, nextCursor, loading, loadFailed, fetchPage }
}
