import { ref, watch, onScopeDispose, type Ref } from 'vue'
import { useGalleryStore, type Tag } from '../stores/gallery'

/** Wait this long after a keystroke before asking the server. */
const SEARCH_DELAY_MS = 150

/**
 * A tag picker's options: the server's best matches for the typed text
 * (exact, then prefix, then containing), leaving out the tags `exclude`
 * names. Empty text offers nothing. The gallery has thousands of tags, so
 * pickers search rather than filtering a list of them all.
 */
export function useTagSearch(term: Ref<string>, exclude: () => readonly number[], limit = 10) {
  const store = useGalleryStore()
  const options = ref<Tag[]>([])
  let searchSeq = 0
  let searchTimer: ReturnType<typeof setTimeout> | undefined

  watch(term, (typed) => {
    clearTimeout(searchTimer)
    // Only the latest search's answer counts; an earlier one may arrive later.
    const seq = ++searchSeq
    const text = typed.trim()
    if (text === '') {
      options.value = []
      return
    }
    searchTimer = setTimeout(() => {
      store.searchTags(text, exclude(), limit).then(
        (tags) => {
          if (seq === searchSeq) {
            options.value = tags
          }
        },
        () => {
          if (seq === searchSeq) {
            options.value = []
          }
        },
      )
    }, SEARCH_DELAY_MS)
  })
  onScopeDispose(() => {
    clearTimeout(searchTimer)
  })

  return options
}
