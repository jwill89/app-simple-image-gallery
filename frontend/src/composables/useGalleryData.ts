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
  /** How many items the whole listing holds. */
  const total = ref(0)
  /** Where GET /media continues after this page; null when nothing follows. */
  const nextCursor = ref<string | null>(null)
  const loading = ref(false)
  const loadFailed = ref(false)

  async function fetchPage(page: number, perPage: number, tags?: string) {
    loading.value = true
    loadFailed.value = false

    try {
      const { data, total: count } = await api.getPage<MediaPage>(
        endpoints.media.page(page, perPage, tags),
      )

      items.value = data?.items ?? []
      total.value = count ?? items.value.length
      totalPages.value = Math.max(1, Math.ceil(total.value / perPage))
      nextCursor.value = data?.nextCursor ?? null
    } catch (e) {
      toastStore.error(getErrorMessage(e, 'Failed to load gallery'))
      loadFailed.value = true
      items.value = []
      total.value = 0
      totalPages.value = 0
      nextCursor.value = null
    } finally {
      loading.value = false
    }
  }

  return { items, total, totalPages, nextCursor, loading, loadFailed, fetchPage }
}
