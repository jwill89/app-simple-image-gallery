# Gallery API Reference

Internal **hybrid REST-RPC** HTTP API for the self-hosted media gallery. All
endpoints are served under the **`/api`** base path and return **JSON**.

The **authoritative, always-current contract** is the generated OpenAPI 3.1
document. Browse it interactively or fetch the raw spec:

- **Interactive reference (Scalar):** [`GET /api/docs`](docs)
- **Raw spec:** [`GET /api/openapi.json`](openapi.json) — committed to the repo as
  [`backend/openapi.json`](openapi.json), regenerated with `composer docs`.

This document is a quick human-oriented overview; the annotations on the
controllers under [`api/Routes/Internal/`](api/Routes/Internal/) and the structure
classes under [`includes/Gallery/Structure/`](includes/Gallery/Structure/) are the source.

---

## Conventions

### HTTP semantics

| Outcome | Shape | Status |
|---------|-------|--------|
| Read | The resource or collection | `200` |
| Create | The created resource | `201` |
| Update (`PUT`/`PATCH`) | The updated resource | `200` |
| Delete | *(empty body)* | `204` |
| RPC action / batch | A small result object | `200` (or `201` for a created record) |
| Error | `{ "error": "<MachineCode>", "message": "<human text>" }` | `4xx` / `5xx` |

- `error` is a stable PascalCase code (e.g. `MediaNotFound`) safe to branch on.
- `message` is for display and may change.
- Cached `GET`s also carry an **`X-Cache: HIT|MISS`** header.

### Authentication

A **Bearer token** in the `Authorization` header:

```
Authorization: Bearer <token>
```

Obtain a token from [`POST /auth/login`](#auth) (valid **24 hours**). The rule:

- **All `GET` requests are public.**
- **State-changing methods require a token**, with an intentionally-public
  allowlist matched by `(method, path)`: `POST /media/by-ids` (batched read),
  `PATCH /media/{id}/tags`, and `DELETE /media/{id}/tags/{tag_id}` (anyone may tag),
  plus `POST /auth/login` itself.

In the tables below: 🌐 = public, 🔒 = token required.

### Rate limiting, CORS & CSRF

- **Rate limit:** 120 requests / 60 s per IP; `POST /auth/login` is throttled
  separately at 10 attempts / 5 min. A `429` carries `Retry-After`.
- **CORS:** responses echo an allowed `Origin` (`GALLERY_ALLOWED_ORIGINS`); credentials allowed.
- **CSRF:** state-changing requests must carry an `Origin`/`Referer` matching an
  allowed origin, else `403 ForbiddenOrigin`.

---

## System

| Method | Path | Auth | Returns |
|--------|------|------|---------|
| GET | `/version` | 🌐 | `{ version, api_version }` |
| GET | `/openapi.json` | 🌐 | The OpenAPI 3.1 document |
| GET | `/docs` | 🌐 | Scalar API reference (HTML) |

## Auth

| Method | Path | Auth | Body | Returns |
|--------|------|------|------|---------|
| POST | `/auth/login` | 🌐 | `{ password }` | `{ token }` |

## Media

| Method | Path | Auth | Body / Params | Returns |
|--------|------|------|---------------|---------|
| GET | `/media/page/{page}[/{per_page}]` | 🌐 | `per_page` clamped 1–200 | `MediaPage` |
| GET | `/media/untagged/{page}[/{per_page}]` | 🌐 | — | `MediaPage` |
| GET | `/media/with-tags/{tag_list}/{page}[/{per_page}]` | 🌐 | `tag_list` comma-separated; leading `-` excludes (e.g. `cat,-dog`) | `MediaPage` |
| GET | `/media/{media_id}` | 🌐 | — | `Media` |
| GET | `/media/random` | 🌐 | — | `Media` |
| GET | `/media/count` | 🌐 | — | `{ count }` |
| POST | `/media/by-ids` | 🌐 | `{ ids: int[] }` (≤ 200) | `Media[]` |
| POST | `/media` | 🔒 | `multipart/form-data` `files[]` (+ optional `fetch_tags`) | `UploadSummary` (`201`) |
| POST | `/media/bulk-delete` | 🔒 | `{ media_ids: int[] }` | `{ deleted, failed, total_deleted }` |
| DELETE | `/media/{media_id}` | 🔒 | — | `204` |
| GET | `/media/{media_id}/tags` | 🌐 | — | `Tag[]` |
| PATCH | `/media/{media_id}/tags` | 🌐 | `{ tag_ids: int[] }` | updated `Tag[]` |
| DELETE | `/media/{media_id}/tags/{tag_id}` | 🌐 | — | updated `Tag[]` |
| POST | `/media/{media_id}/danbooru-tags` | 🔒 | `{ danbooru_post_id? }` | `DanbooruFetchResult` |

## Tags

| Method | Path | Auth | Body / Params | Returns |
|--------|------|------|---------------|---------|
| GET | `/tags` | 🌐 | — | `Tag[]` |
| GET | `/tags/display` | 🌐 | — | `TagListItem[]` (with category + counts) |
| GET | `/tags/{tag_id}` | 🌐 | — | `Tag` |
| POST | `/tags` | 🔒 | `{ tag_name, category_id? }` | `Tag` (`201`) |
| PUT | `/tags/{tag_id}` | 🔒 | `{ tag_name, category_id? }` | `Tag` |
| DELETE | `/tags/{tag_id}` | 🔒 | — | `204` |
| DELETE | `/tags/{tag_id}/migrate-to/{target_tag_id}` | 🔒 | migrate media to the target, then delete | `204` |
| POST | `/tags/{tag_id}/migrate` | 🔒 | `{ target_tag_id }` | `MigrateResult` |

### Tag categories

| Method | Path | Auth | Body | Returns |
|--------|------|------|------|---------|
| GET | `/tag-categories` | 🌐 | — | `TagCategory[]` |
| POST | `/tag-categories` | 🔒 | `{ category_name, category_short, color?, description?, sort_order? }` | `TagCategory` (`201`) |
| PUT | `/tag-categories/{category_id}` | 🔒 | same fields as create | `TagCategory` |
| DELETE | `/tag-categories/{category_id}` | 🔒 | — (must have no tags) | `204` |

### Tag implications

| Method | Path | Auth | Body | Returns |
|--------|------|------|------|---------|
| GET | `/tag-implications` | 🌐 | — | `TagImplication[]` |
| POST | `/tag-implications` | 🔒 | `{ tag_id, implied_tag_id }` (cycles rejected) | `TagImplication` (`201`) |
| DELETE | `/tag-implications/{tag_id}/{implied_tag_id}` | 🔒 | — | `204` |

## Danbooru import rules

| Method | Path | Auth | Body | Returns |
|--------|------|------|------|---------|
| GET | `/danbooru/category-mappings` | 🌐 | — | `CategoryMapping[]` |
| POST | `/danbooru/category-mappings` | 🔒 | `{ danbooru_category_id, danbooru_category_name, gallery_category_id }` | `CategoryMapping` (`201`) |
| DELETE | `/danbooru/category-mappings/{danbooru_category_id}` | 🔒 | — | `204` |
| GET | `/danbooru/tag-mappings` | 🌐 | — | `TagMapping[]` |
| POST | `/danbooru/tag-mappings` | 🔒 | `{ danbooru_tag, gallery_tag }` | `TagMapping` (`201`) |
| PUT | `/danbooru/tag-mappings/{id}` | 🔒 | `{ danbooru_tag, gallery_tag }` | `TagMapping` |
| DELETE | `/danbooru/tag-mappings/{id}` | 🔒 | — | `204` |

## Duplicates

Duplicate detection is image-only, based on perceptual fingerprinting.

| Method | Path | Auth | Body | Returns |
|--------|------|------|------|---------|
| GET | `/duplicates/report` | 🔒 | — | `DuplicateReport` (dismissed pairs filtered out) |
| POST | `/duplicates/scan` | 🔒 | — | `ScanResult` |
| POST | `/duplicates/dismissals` | 🔒 | `{ media_id_1, media_id_2 }` | `{ dismissed, media_id_1, media_id_2 }` (`201`) |

---

## Resource shapes

Full JSON schemas for every resource (`Media`, `Tag`, `TagCategory`, `MediaPage`,
`TagListItem`, `TagImplication`, `CategoryMapping`, `TagMapping`, `DuplicateReport`,
`UploadSummary`, `DanbooruFetchResult`, …) live in the OpenAPI document under
`components.schemas`. The frontend consumes them as generated TypeScript types
(`frontend/src/types/api.generated.ts`, via `npm run gen:types`).
