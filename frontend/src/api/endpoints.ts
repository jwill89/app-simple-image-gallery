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
    if (value !== undefined && value !== '' && value !== false) search.set(key, String(value))
  }
  const text = search.toString()
  return text === '' ? '' : `?${text}`
}

export const endpoints = {
  version: '/version',
  auth: {
    /** Whether sign-up is open, and the Turnstile site key. */
    config: '/auth/config',
    register: '/auth/register',
    login: '/auth/login',
    /** The account the token signs in. */
    me: '/auth/me',
    /** DELETE: revoke the token the request carries (log out). */
    logout: '/auth/token',
  },
  account: {
    /** DELETE, with { password }: the account itself. */
    self: '/account',
    password: '/account/password',
    blockedTags: '/account/blocked-tags',
    /** PUT blocks the tag, DELETE unblocks it. */
    blockedTag: (tagId: Id) => `/account/blocked-tags/${tagId}`,
  },
  lists: {
    all: '/lists',
    byId: (listId: Id) => `/lists/${listId}`,
    /** Every media id in the list. */
    ids: (listId: Id) => `/lists/${listId}/ids`,
    /** GET a page of it (latest added first); POST { media_ids } adds many. */
    media: (listId: Id, page?: number, perPage?: number) =>
      `/lists/${listId}/media${query({ page, per_page: perPage })}`,
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
    page: (page: Id, perPage: Id) => `/media/page/${page}/${perPage}`,
    untagged: (page: Id, perPage: Id) => `/media/untagged/${page}/${perPage}`,
    /** `tagList` is a comma-separated list; a leading '-' excludes (e.g. "cat,-dog"). */
    withTags: (tagList: string, page: Id, perPage: Id) =>
      `/media/with-tags/${encodeURIComponent(tagList)}/${page}/${perPage}`,
    byId: (id: Id) => `/media/${id}`,
    /** The item with its tags, in display order, in one request. */
    byIdWithTags: (id: Id) => `/media/${id}?include=tags`,
    /** The items after a cursor (a page's or a feed's `next_cursor`), in one listing. */
    feed: (params: { cursor: string; perPage: number; tags?: string; untagged?: boolean }) =>
      `/media${query({ cursor: params.cursor, per_page: params.perPage, tags: params.tags, untagged: params.untagged === true ? 1 : undefined })}`,
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
    list: '/tags',
    /**
     * The best matches for a name: exact, then prefix, then containing. An
     * empty term (still sent: it is what asks for a search) lists the first by name.
     */
    search: (term: string, limit: number, exclude: readonly number[] = []) => {
      const params = new URLSearchParams({ search: term, limit: String(limit) })
      if (exclude.length > 0) params.set('exclude', exclude.join(','))
      return `/tags?${params.toString()}`
    },
    byNames: (names: readonly string[]) => `/tags${query({ names: names.join(',') })}`,
    byIds: (ids: readonly number[]) => `/tags${query({ ids: ids.join(',') })}`,
    /** A page of the management list (tags with category and counts). */
    display: (params: {
      page?: number
      perPage?: number
      search?: string
      sort?: 'tag_name' | 'category_name' | 'media_count'
      direction?: 'asc' | 'desc'
      ids?: readonly number[]
    }) =>
      `/tags/display${query({ page: params.page, per_page: params.perPage, search: params.search, sort: params.sort, direction: params.direction, ids: params.ids?.join(',') })}`,
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
