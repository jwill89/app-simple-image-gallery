/**
 * Ergonomic type aliases over the generated OpenAPI types.
 *
 * `api.generated.ts` is produced from the backend spec (`npm run gen:types`)
 * and is never hand-edited. openapi-typescript marks every property optional;
 * for the entity/response shapes the API always returns in full we re-expose
 * them with required fields here, so app code doesn't drown in `undefined`
 * checks. Import domain types from `@/types` (this file), not the generated one.
 */
import type { components } from './api.generated'

type Schemas = components['schemas']

// ── Entities (always returned in full) ──────────────────────────────
/** A media item (image or video). */
export type Media = Required<Schemas['Media']>
/** An alias for {@link Media}. */
export type MediaItem = Media
/** A tag. */
export type Tag = Required<Schemas['Tag']>
/** A tag category. */
export type TagCategory = Required<Schemas['TagCategory']>
/** A tag enriched with category name + usage counts (GET /tags/display). */
export type TagListItem = Required<Schemas['TagListItem']>
/** A tag implication (GET /tag-implications). */
export type TagImplication = Required<Schemas['TagImplication']>
/** A Danbooru→gallery category mapping. */
export type CategoryMapping = Required<Schemas['CategoryMapping']>
/** A Danbooru→gallery tag-name mapping. */
export type TagMapping = Required<Schemas['TagMapping']>
/** An account (GET /auth/session, the admin's GET /users). */
export type User = Required<Schemas['User']>
/** One of an account's lists of media; the default one is "Favorites". */
export type FavoriteList = Required<Schemas['FavoriteList']>
/** A tag search an account keeps by name; `query` as the gallery URL writes it. */
export type SavedSearch = Required<Schemas['SavedSearch']>

// ── Composite responses ─────────────────────────────────────────────
/** A numbered page of media (GET /media/page/…, /media/untagged/…, /media/with-tags/…). */
export interface MediaPage {
  items: Media[]
  total_pages: number
  current_page: number
  /** Continue after this page with GET /media?cursor=…; null when nothing follows. */
  next_cursor: string | null
}

/** The media after a cursor (GET /media?cursor=…). */
export interface MediaFeed {
  items: Media[]
  next_cursor: string | null
}

/** A media item with its tags, in display order (GET /media/{id}?include=tags). */
export type MediaWithTags = Media & { tags: Tag[] }

/** A page of the tag-management list (GET /tags/display). */
export interface TagListPage {
  items: TagListItem[]
  total: number
  current_page: number
  total_pages: number
}

/** Where a media item's queued Danbooru import stands (GET /media/tag-imports). */
export type TagImportStatus = Required<Schemas['TagImportStatus']>

export type Count = Required<Schemas['Count']>
/** A login or sign-up's answer: the account this browser is now signed in as. */
export interface SignInResponse {
  user: User
}

/** A passkey the signed-in account can sign in with (GET /account/passkeys). */
export type Passkey = Required<Schemas['Passkey']>

/** Who this browser is signed in as (GET /auth/session); null when signed out. */
export interface SessionResponse {
  user: User | null
}

/** What the sign-in and sign-up pages need (GET /auth/config). */
export interface AuthConfig {
  signup_open: boolean
  /** Turnstile's site key; null while the server doesn't check for bots. */
  turnstile_site_key: string | null
}
export type BulkDeleteResult = Required<Schemas['BulkDeleteResult']>

/** Result of importing Danbooru tags for a media item. */
export interface DanbooruFetchResult {
  tags: Tag[]
  method: string
  tags_applied: number
  tags_created: number
}

interface DuplicateMatchMedia {
  media_id: number
  file_name: string
  hash: string
}
export interface DuplicateMatch {
  media_1: DuplicateMatchMedia
  media_2: DuplicateMatchMedia
  distance: number | null
  ssim: number | null
}
export interface DuplicateReport {
  report_file: string
  generated_at: string | null
  images_compared: number | null
  duplicates_found: number
  matches: DuplicateMatch[]
}
