import { ref } from 'vue'
import { useApi, getErrorMessage } from './useApi'
import { useToastStore } from '../stores/toast'
import { useGalleryStore } from '../stores/gallery'
import { endpoints } from '../api/endpoints'
import type { Tag, MediaItem, MediaWithTags } from '../types'

export function useMediaTags() {
  const api = useApi()
  const toastStore = useToastStore()
  const store = useGalleryStore()
  const tags = ref<Tag[]>([])
  const mediaItem = ref<MediaItem | null>(null)
  const loading = ref(false)
  const loadFailed = ref(false)

  async function fetchMediaAndTags(mediaId: number) {
    loading.value = true
    loadFailed.value = false

    try {
      // The item and its tags in one request.
      const item = await api.get<MediaWithTags>(endpoints.media.byIdWithTags(mediaId))
      if (item) {
        const { tags: itemTags, ...media } = item
        mediaItem.value = media
        tags.value = itemTags
        store.rememberTags(itemTags)
      } else {
        mediaItem.value = null
        tags.value = []
      }
    } catch (e) {
      toastStore.error(getErrorMessage(e, 'Failed to load media'))
      loadFailed.value = true
    } finally {
      loading.value = false
    }
  }

  async function addTags(mediaId: number, tagIds: number[]) {
    try {
      const updatedTags = await api.patch<Tag[]>(endpoints.media.tags(mediaId), { tag_ids: tagIds })
      tags.value = updatedTags ?? []
      // What it implies came along: remembered, so chips can name them.
      store.rememberTags(tags.value)
    } catch (e) {
      toastStore.error(getErrorMessage(e, 'Failed to add tags'))
    }
  }

  async function removeTag(mediaId: number, tagId: number) {
    try {
      const updatedTags = await api.del<Tag[]>(endpoints.media.removeTag(mediaId, tagId))
      tags.value = updatedTags ?? []
    } catch (e) {
      toastStore.error(getErrorMessage(e, 'Failed to remove tag'))
    }
  }

  return { tags, mediaItem, loading, loadFailed, fetchMediaAndTags, addTags, removeTag }
}
