import { defineStore } from 'pinia'
import { ref, computed, watch } from 'vue'
import { useApi, getErrorMessage } from '../composables/useApi'
import { endpoints } from '../api/endpoints'
import type { FavoriteList } from '../types'
import { useToastStore } from './toast'

const STORAGE_KEY = 'gallery_favorites'

/** The most media ids one request adds to a list (the server's limit). */
const IMPORT_BATCH = 2000

function loadFromStorage(): Set<number> {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (raw) {
      const arr: unknown = JSON.parse(raw)
      if (Array.isArray(arr)) return new Set(arr.filter(Number.isFinite) as number[])
    }
  } catch {
    // Corrupted data — start fresh
  }
  return new Set()
}

function saveToStorage(ids: Set<number>): void {
  localStorage.setItem(STORAGE_KEY, JSON.stringify([...ids]))
}

/**
 * Favorites: the heart on a card. Signed out, they live in this browser's
 * local storage. Signed in, they are the account's default list ("Favorites")
 * on the server, beside any other lists it keeps; signing in moves this
 * browser's favorites into it.
 */
export const useFavoritesStore = defineStore('favorites', () => {
  const api = useApi()
  const toast = useToastStore()

  const localIds = ref(loadFromStorage())
  watch(localIds, (v) => {
    saveToStorage(v)
  })

  const signedInState = ref(false)
  const lists = ref<FavoriteList[]>([])
  /** What the account's default list holds. */
  const accountIds = ref(new Set<number>())

  const isAccount = computed(() => signedInState.value)
  const ids = computed(() => (signedInState.value ? accountIds.value : localIds.value))
  const count = computed(() => ids.value.size)
  const defaultList = computed(() => lists.value.find((l) => l.is_default) ?? null)

  function isFavorite(mediaId: number): boolean {
    return ids.value.has(mediaId)
  }

  function withId(set: Set<number>, mediaId: number, present: boolean): Set<number> {
    const next = new Set(set)
    if (present) next.add(mediaId)
    else next.delete(mediaId)
    return next
  }

  function adjustCount(listId: number, by: number): void {
    lists.value = lists.value.map((l) =>
      l.list_id === listId ? { ...l, media_count: Math.max(0, l.media_count + by) } : l,
    )
  }

  /** The heart: in or out of the default list (or this browser's favorites). */
  async function toggle(mediaId: number): Promise<void> {
    if (!signedInState.value) {
      localIds.value = withId(localIds.value, mediaId, !localIds.value.has(mediaId))
      return
    }
    const list = defaultList.value
    if (list) await setInList(list.list_id, mediaId, !accountIds.value.has(mediaId))
  }

  /** Put media in a list or take it out; shown at once, undone if the server refuses. */
  async function setInList(listId: number, mediaId: number, present: boolean): Promise<void> {
    const isDefault = listId === defaultList.value?.list_id
    if (isDefault) accountIds.value = withId(accountIds.value, mediaId, present)
    try {
      if (present) await api.put(endpoints.lists.item(listId, mediaId))
      else await api.del(endpoints.lists.item(listId, mediaId))
      adjustCount(listId, present ? 1 : -1)
    } catch (e) {
      if (isDefault) accountIds.value = withId(accountIds.value, mediaId, !present)
      toast.error(getErrorMessage(e, 'Could not change the list.'), 6000, 'Lists')
    }
  }

  /** On sign-in: the account's lists, with this browser's favorites moved into its default one. */
  async function signedIn(): Promise<void> {
    signedInState.value = true
    lists.value = (await api.get<FavoriteList[]>(endpoints.lists.all)) ?? []
    const list = defaultList.value
    if (!list) return

    const local = [...localIds.value]
    if (local.length > 0) {
      let added = 0
      for (let i = 0; i < local.length; i += IMPORT_BATCH) {
        const result = await api.post<{ added: number }>(endpoints.lists.media(list.list_id), {
          media_ids: local.slice(i, i + IMPORT_BATCH),
        })
        added += result?.added ?? 0
      }
      localIds.value = new Set()
      if (added > 0) {
        toast.success(
          `Moved ${added.toLocaleString()} favorite${added === 1 ? '' : 's'} from this browser into your account.`,
          6000,
          'Favorites',
        )
        await reloadLists()
      }
    }
    accountIds.value = new Set((await api.get<number[]>(endpoints.lists.ids(list.list_id))) ?? [])
  }

  function signedOut(): void {
    signedInState.value = false
    lists.value = []
    accountIds.value = new Set()
  }

  async function reloadLists(): Promise<void> {
    lists.value = (await api.get<FavoriteList[]>(endpoints.lists.all)) ?? lists.value
  }

  async function createList(name: string): Promise<FavoriteList | undefined> {
    const list = await api.post<FavoriteList>(endpoints.lists.all, { name })
    if (list) lists.value = [...lists.value, list]
    return list
  }

  async function renameList(listId: number, name: string): Promise<void> {
    const list = await api.patch<FavoriteList>(endpoints.lists.byId(listId), { name })
    if (list) lists.value = lists.value.map((l) => (l.list_id === listId ? list : l))
  }

  async function deleteList(listId: number): Promise<void> {
    await api.del(endpoints.lists.byId(listId))
    lists.value = lists.value.filter((l) => l.list_id !== listId)
  }

  /** Signed out: drop favorites whose media no longer exists. */
  function prune(validIds: Set<number>): void {
    const next = new Set([...localIds.value].filter((id) => validIds.has(id)))
    if (next.size !== localIds.value.size) localIds.value = next
  }

  /** Signed out: this browser's favorites. */
  function allIds(): number[] {
    return [...localIds.value]
  }

  return {
    ids,
    count,
    lists,
    defaultList,
    isAccount,
    isFavorite,
    toggle,
    setInList,
    signedIn,
    signedOut,
    reloadLists,
    createList,
    renameList,
    deleteList,
    prune,
    allIds,
  }
})
