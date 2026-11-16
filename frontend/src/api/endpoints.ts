/**
 * Central registry of API paths (relative to the `/api` base handled by useApi).
 *
 * Keeping paths here — rather than scattered string literals — means a route
 * rename touches one file, and the query builder for `GET /media` lives beside
 * the routes it serves.
 */

type Id = string | number

/** A query string from the set values, `?`-prefixed, or '' when none is set. */
function query(params: Record<string, string | number | boolean | undefined>): string {
  const search = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== '' && value !== false) {
      search.set(key, String(value))
    }
  }
  const text = search.toString()
  return text === '' ? '' : `?${text}`
}

/**
 * GET /media: a page of media, newest first, of the whole gallery, a tag search
 * (`tags`, a comma-separated list where a leading '-' excludes, e.g. "cat,-dog")
 * or the untagged items. A numbered page is `limit` items from `offset`; the
 * items after a page are the ones after its `nextCursor`.
 */
function mediaList(params: {
  tags?: string
  untagged?: boolean
  limit: number
  offset?: number
  cursor?: string
}): string {
  const { tags, untagged, limit, offset, cursor } = params
  return `/media${query({ tags, untagged, limit, offset, cursor })}`
}

/** A gallery route's `tags` ("cat,-dog", or "untagged") as GET /media's filter. */
function listingFilter(tags?: string): { tags?: string; untagged?: boolean } {
  if (tags === 'untagged') {
    return { untagged: true }
  }
  return tags ? { tags } : {}
}

export const endpoints = {
  version: '/version',
  auth: {
    /** Whether sign-up is open, and the Turnstile site key. */
    config: '/auth/config',
    register: '/auth/register',
    login: '/auth/login',
    /** GET: who this browser is signed in as. DELETE: sign it out. */
    session: '/auth/session',
    /** POST: a challenge to sign in with a passkey. */
    passkeyOptions: '/auth/passkey/options',
    /** POST, with { credential, remember }: sign in with the passkey's answer. */
    passkey: '/auth/passkey',
  },
  account: {
    /** DELETE, with { password }: the account itself. */
    self: '/account',
    password: '/account/password',
    blockedTags: '/account/blocked-tags',
    /** PUT blocks the tag, DELETE unblocks it. */
    blockedTag: (tagId: Id) => `/account/blocked-tags/${tagId}`,
    /** GET lists them; POST, with { credential, name? }, adds one. */
    passkeys: '/account/passkeys',
    /** POST, with { password }: a challenge to add a passkey with. */
    passkeyOptions: '/account/passkeys/options',
    /** PATCH renames it, DELETE removes it. */
    passkey: (passkeyId: Id) => `/account/passkeys/${passkeyId}`,
  },
  lists: {
    all: '/lists',
    byId: (listId: Id) => `/lists/${listId}`,
    /** Every media id in the list. */
    ids: (listId: Id) => `/lists/${listId}/ids`,
    /** GET a page of it (latest added first); POST { mediaIds } adds many. */
    media: (listId: Id, params: { limit?: number; offset?: number } = {}) =>
      `/lists/${listId}/media${query({ limit: params.limit, offset: params.offset })}`,
    /** PUT adds the media to the list, DELETE takes it out. */
    item: (listId: Id, mediaId: Id) => `/lists/${listId}/media/${mediaId}`,
    /** The ids of the account's lists that hold this media. */
    holding: (mediaId: Id) => `/media/${mediaId}/lists`,
  },
  savedSearches: {
    all: '/saved-searches',
    byId: (searchId: Id) => `/saved-searches/${searchId}`,
  },
  users: {
    all: '/users',
    byId: (userId: Id) => `/users/${userId}`,
  },
  media: {
    /** Numbered page `page` (from 1) of a gallery route's listing: its `tags`, or "untagged". */
    page: (page: number, perPage: number, tags?: string) =>
      mediaList({ ...listingFilter(tags), limit: perPage, offset: (page - 1) * perPage }),
    /** The `limit` items after a page's `nextCursor`, in a gallery route's listing. */
    after: (cursor: string, limit: number, tags?: string) =>
      mediaList({ ...listingFilter(tags), limit, cursor }),
    byId: (id: Id) => `/media/${id}`,
    /** The item with its tags, in display order, in one request. */
    byIdWithTags: (id: Id) => `/media/${id}?include=tags`,
    /** Where these items' queued Danbooru imports stand. */
    tagImports: (ids: readonly number[]) => `/media/tag-imports${query({ ids: ids.join(',') })}`,
    random: '/media/random',
    count: '/media/count',
    byIds: '/media/by-ids',
    bulkDelete: '/media/bulk-delete',
    upload: '/media',
    tags: (id: Id) => `/media/${id}/tags`,
    removeTag: (mediaId: Id, tagId: Id) => `/media/${mediaId}/tags/${tagId}`,
    danbooruTags: (id: Id) => `/media/${id}/danbooru-tags`,
  },
  tags: {
    /**
     * A page of tags (X-Total-Count says how many match): by `ids` or `names`
     * (at most 100 each), ranked for a `search` term (exact, then prefix, then
     * containing), or kept by a `filter` on tag or category names, ordered by
     * `sort`; `include: 'counts'` adds each tag's media and implication counts.
     */
    list: (params: {
      ids?: readonly number[]
      names?: readonly string[]
      search?: string
      filter?: string
      exclude?: readonly number[]
      sort?: 'tagName' | 'categoryName' | 'mediaCount'
      direction?: 'asc' | 'desc'
      limit?: number
      offset?: number
      include?: 'counts'
    }) => {
      const search = new URLSearchParams()
      const set = (key: string, value: string | number | undefined) => {
        if (value !== undefined) {
          search.set(key, String(value))
        }
      }
      set('ids', params.ids?.join(','))
      set('names', params.names?.join(','))
      // An empty search term is still sent: it is what asks for a ranking.
      set('search', params.search)
      set('filter', params.filter === '' ? undefined : params.filter)
      set(
        'exclude',
        params.exclude !== undefined && params.exclude.length > 0
          ? params.exclude.join(',')
          : undefined,
      )
      set('sort', params.sort)
      set('direction', params.direction)
      set('limit', params.limit)
      set('offset', params.offset)
      set('include', params.include)
      const text = search.toString()
      return text === '' ? '/tags' : `/tags?${text}`
    },
    byId: (id: Id) => `/tags/${id}`,
    create: '/tags',
    migrate: (id: Id) => `/tags/${id}/migrate`,
    deleteMigrateTo: (id: Id, targetId: Id) => `/tags/${id}/migrate-to/${targetId}`,
  },
  tagCategories: {
    list: '/tag-categories',
    byId: (id: Id) => `/tag-categories/${id}`,
  },
  tagImplications: {
    list: '/tag-implications',
    byPair: (tagId: Id, impliedTagId: Id) => `/tag-implications/${tagId}/${impliedTagId}`,
  },
  danbooru: {
    categoryMappings: '/danbooru/category-mappings',
    categoryMapping: (danbooruCategoryId: Id) =>
      `/danbooru/category-mappings/${danbooruCategoryId}`,
    tagMappings: '/danbooru/tag-mappings',
    tagMapping: (id: Id) => `/danbooru/tag-mappings/${id}`,
  },
  duplicates: {
    report: '/duplicates/report',
    scan: '/duplicates/scan',
    dismissals: '/duplicates/dismissals',
  },
} as const
