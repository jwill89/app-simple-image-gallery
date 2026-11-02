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
/** Backward-compatible alias for {@link Media}. */
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

// ── Composite responses ─────────────────────────────────────────────
/** A page of media (GET /media). */
export interface MediaPage {
  items: Media[]
  total_pages: number
  current_page: number
}

export type Count = Required<Schemas['Count']>
export type LoginResponse = Required<Schemas['LoginResponse']>
export type BulkDeleteResult = Required<Schemas['BulkDeleteResult']>

/** Result of importing Danbooru tags for a media item. */
export interface DanbooruFetchResult {
  tags: Tag[]
  all_tags: Tag[]
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
