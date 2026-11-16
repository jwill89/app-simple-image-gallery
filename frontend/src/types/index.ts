/**
 * Domain types over the generated OpenAPI types.
 *
 * `api.generated.ts` is produced from the backend spec (`npm run gen:types`)
 * and is never hand-edited. Each entity and response the API sends is
 * re-exported here by name; import domain types from `@/types` (this file),
 * not the generated one. Only client-side shapes are written by hand.
 */
import type { components } from './api.generated'

type Schemas = components['schemas']

// ── Entities ────────────────────────────────────────────────────────
/** A media item (image or video). */
export type Media = Schemas['Media']
/** An alias for {@link Media}. */
export type MediaItem = Media
/** A tag; with `include=counts`, also its media and implication counts. */
export type Tag = Schemas['Tag']
/** A tag category. */
export type TagCategory = Schemas['TagCategory']
/** A tag implication (GET /tag-implications). */
export type TagImplication = Schemas['TagImplication']
/** A Danbooru→gallery category mapping. */
export type CategoryMapping = Schemas['CategoryMapping']
/** A Danbooru→gallery tag-name mapping. */
export type TagMapping = Schemas['TagMapping']
/** An account (GET /auth/session, the admin's GET /users). */
export type User = Schemas['User']
/** One of an account's lists of media; the default one is "Favorites". */
export type FavoriteList = Schemas['FavoriteList']
/** A tag search an account keeps by name; `query` as the gallery URL writes it. */
export type SavedSearch = Schemas['SavedSearch']
/** A passkey the signed-in account can sign in with (GET /account/passkeys). */
export type Passkey = Schemas['Passkey']

// ── Responses ───────────────────────────────────────────────────────
/** A page of GET /media: its items, and the cursor to go on from (null at the end). */
export type MediaPage = Schemas['MediaPage']
/** A media item with its tags, in display order (GET /media/{id}?include=tags). */
export type MediaWithTags = Schemas['Media'] & { tags: Tag[] }
/** Where a media item's queued Danbooru import stands (GET /media/tag-imports). */
export type TagImportStatus = Schemas['TagImportStatus']
export type Count = Schemas['Count']
/** A login or sign-up's answer: the account this browser is now signed in as. */
export type SignInResponse = Schemas['SignInResponse']
/** Who this browser is signed in as (GET /auth/session); null when signed out. */
export type SessionResponse = Schemas['SessionResponse']
/** What the sign-in and sign-up pages need (GET /auth/config). */
export type AuthConfig = Schemas['AuthConfig']
export type BulkDeleteResult = Schemas['BulkDeleteResult']
/** Result of importing Danbooru tags for a media item. */
export type DanbooruFetchResult = Schemas['DanbooruFetchResult']
export type DuplicateMatch = Schemas['DuplicateMatch']
export type DuplicateReport = Schemas['DuplicateReport']
/** Every uploaded file's outcome, with totals (POST /media). */
export type UploadSummary = Schemas['UploadSummary']
