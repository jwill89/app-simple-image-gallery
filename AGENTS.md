# AGENTS.md

Operational guide for AI coding agents (and humans) working in this repository.
Read this before making changes. It describes the architecture, conventions,
data model, commands, and the non-obvious gotchas that will bite you.

> **The backend is Laravel** (since 5.0.0, live from 2026-11-03). It replaced a Slim 4 app
> without changing the API contract. How that was done, and every deliberate difference, is in
> [docs/laravel-migration.md](docs/laravel-migration.md). The contract recorded from the Slim app
> is frozen in `backend/tests/Fixtures/contract/` and replayed by the tests.

---

## 1. What this project is

A self-hosted **personal media gallery** (images + videos) with a Danbooru-style
tagging system. It is a single-user/small-trusted-group app, not a multi-tenant
SaaS. Key features:

- Unified media browsing (images, GIFs, videos) with pagination and infinite scroll.
- Hierarchical **tag system** with categories, colors, and **implications**
  (tag A implies tag B → applying A auto-applies B, transitively).
- **Tag-based search** with include/exclude (`+tag`, `-tag`) filters.
- **Danbooru API integration**: auto-import tags by MD5 hash, with IQDB visual-similarity
  fallback. Import rules (category + tag-name mappings) are DB-driven and editable in the UI.
- **Duplicate detection** via perceptual hashing (LSH candidate generation → Hamming
  distance → a worst-region comparison of the thumbnails that tells re-saves from variants).
- **Favorites** (client-side, `localStorage`).
- Admin-gated **uploads** and **deletes** via a single shared password → bearer token.
- A nightly **ingest** of files dropped into the media folder, and queued **background work**:
  ingest, Danbooru imports, and rebuilding thumbnails, fingerprints or metadata.
- A PWA-ish **service worker** for thumbnail/API caching and adjacent-page prefetch.

### Tech stack

| Layer | Tech |
|------|------|
| Backend | PHP **8.5**, **Laravel 13**: Eloquent, Form Requests, API Resources, Sanctum, the database queue, the scheduler |
| Database | **SQLite** (one file, WAL mode); Laravel migrations in `backend/database/migrations/` |
| Image/video tooling | `ffmpeg`/`ffprobe` through Laravel's `Process`, PHP GD, `intervention/imagehash` (perceptual hash, always on GD) |
| HTTP client | Laravel's `Http` for Danbooru, faked in tests |
| API docs | swagger-php 7 attributes → committed `backend/openapi.json`, served at `/api/openapi.json` and `/api/docs` (Scalar) |
| Frontend | **Vue 3** (`<script setup>` + Composition API), TypeScript, [Pinia](https://pinia.vuejs.org/), Vue Router |
| Build | **Vite** on Node **26+** (outputs to `frontend/dist`) |
| Styling | **Bulma 1.x** + custom `style.css` (token layer), FontAwesome (kit script) |
| Tests | **Pest 5** (`backend/tests/`, in-memory SQLite) + **Vitest** (`frontend/src/__tests__/`, happy-dom) |

---

## 2. Repository layout

```
/
├── backend/                     # The Laravel app; its README covers local setup and the checks
│   ├── app/
│   │   ├── Http/
│   │   │   ├── Controllers/Api/       # One controller per resource; every action #[OAS\*]-annotated
│   │   │   ├── Controllers/SpaController.php  # The SPA shell: index.html + each page's link-preview tags
│   │   │   ├── Middleware/            # SecurityHeaders, VerifyOrigin (the CSRF origin check)
│   │   │   ├── Requests/              # ApiRequest (contract-shaped validation errors) + one per write
│   │   │   └── Resources/             # The JSON shapes: Media, Tag, TagCategory, mappings, …
│   │   ├── Models/                    # Eloquent: Media, Tag, TagCategory, DismissedDuplicate,
│   │   │                              #   DanbooruCategoryMapping, DanbooruTagMapping, User (the admin)
│   │   ├── Services/
│   │   │   ├── Danbooru/              # DanbooruTagger (MD5 → IQDB, import rules), DanbooruFailure
│   │   │   ├── Duplicates/            # DuplicateScanner (LSH → Hamming → RegionDifference), ScanSettings
│   │   │   ├── Media/                 # MediaIngest, MediaMetadata, Thumbnailer (ffmpeg)
│   │   │   └── Fingerprinter.php      # The perceptual hash (pHash on GD)
│   │   ├── Jobs/                      # ProcessNewMedia, ImportDanbooruTags, RebuildFromFile
│   │   ├── Console/Commands/          # gallery:ingest, gallery:import-tags, gallery:regenerate, gallery:dupes
│   │   ├── Support/                   # ResponseCache + CacheGroup: cached reads, invalidated by group
│   │   ├── Exceptions/                # ApiException + ApiExceptionRenderer: { error, message } for all of /api
│   │   ├── OpenApi/                   # Global OAS metadata (holds API_VERSION) + doc-only response schemas
│   │   └── Providers/AppServiceProvider.php  # rate limiters, CORS origins, the media link, unwrapped resources
│   ├── bootstrap/app.php        # Middleware stack and exception rendering
│   ├── config/gallery.php       # Every gallery setting, read from .env (code never calls env())
│   ├── database/migrations/     # The schema; the baseline is the Slim app's, created only where missing
│   ├── routes/
│   │   ├── api.php              # Every /api route: public ones first, then the auth:sanctum group
│   │   ├── web.php              # The SPA shell's fallback route: everything outside /api
│   │   └── console.php          # The schedule
│   ├── public/                  # Apache's DocumentRoot: index.php, .htaccess (upload limits), the built SPA
│   │                            #   (the deploy copies frontend/dist here), media → the media folder
│   ├── resources/spa/index.html # The built SPA's index.html, which the shell serves (deploy copies it; gitignored)
│   ├── storage/                 # Logs, the file cache (responses, rate limits, locks); contents gitignored
│   ├── tests/
│   │   ├── Feature/             # HTTP, console, jobs, models, services; Contract/ replays the recordings
│   │   ├── Unit/                # The OpenAPI document, the fingerprinter, the region difference
│   │   ├── Support/             # The contract replay (Contract/), fixture seeder, OpenAPI builder, test images
│   │   └── Fixtures/            # Frozen contract recordings (contract/), contract.sql, schema.sql, files
│   ├── bin/openapi.php          # `composer docs`: writes openapi.json
│   ├── openapi.json             # Generated, committed; CI fails if it is stale
│   ├── pint.json · phpstan.neon · rector.php · phpunit.xml
│   └── .env.example             # Every setting, documented
│
├── frontend/                  # Vue 3 + Vite source
│   ├── vite.config.ts         # Vite + Vitest config (build outDir, dev proxy, test block)
│   ├── vitest.setup.ts        # Vitest setup (pins TZ=UTC)
│   ├── eslint.config.ts       # shared config (eslint-config submodule) + this project's rules
│   ├── stylelint.config.js    # standard CSS rules + the palette rule
│   ├── knip.ts                # unused files / exports / dependencies
│   ├── src/
│   │   ├── main.ts            # App bootstrap, global error handler, SW registration
│   │   ├── router/index.ts    # Routes (lazy-loaded views)
│   │   ├── stores/            # Pinia: gallery (tags/categories/totals/blur), favorites, toast
│   │   ├── composables/       # useApi (fetch wrapper), useGalleryData, useMediaTags, usePrefetch
│   │   ├── components/        # Reusable UI (GalleryCard, TagBadge, TagMultiSelect, modals…)
│   │   ├── views/             # Route-level pages
│   │   ├── constants/         # categories.ts (color → CSS class helpers), uploads.ts (upload batching)
│   │   ├── __tests__/         # Vitest specs (*.spec.ts): useApi, categories, stores, uploads, UploadView
│   │   └── style.css          # Custom styles + extended Bulma color palette
│   ├── public/sw.js           # Service worker (caching + prefetch)
│   └── dist/                  # Vite build output (gitignored); the deploy copies it into backend/public
│
├── .github/workflows/ci.yml   # CI: lint, static analysis, tests + build per side; PSScriptAnalyzer; release
├── .editorconfig              # LF, UTF-8, 4 spaces (2 for frontend files, YAML, Markdown)
├── PSScriptAnalyzerSettings.psd1  # PowerShell lint settings for scripts/
├── README.md · CONTRIBUTING.md · CHANGELOG.md · LICENSE
├── docs/laravel-migration.md  # How the backend moved from Slim to Laravel: phases, contract changes, deployment
└── scripts/                   # Dev-only: deploy.ps1 (PuTTY pscp/plink → droplet) + config template
```

### Layering convention (backend)

`Route → middleware → Form Request → Controller → Model / Service → Eloquent (SQLite)`

- **Models** are Eloquent, with the domain rules that belong to one table: `Media::typeOf()`
  and `isAnimatedGif()`, `Media::thumbnailPaths()`, the tag-search scopes, `Tag::normalizeName()`,
  `Tag::withImplied()`, `Tag::moveMediaTo()`. Deleting a `Media` removes its files, and saving or
  deleting a model clears the cached reads it affects (§9).
- **Form Requests** validate a write's body. They extend `ApiRequest`, which fails with the
  first broken rule's code and status (the endpoint's own, usually 400) as `{ error, message }`,
  plus `errors` listing every broken field, never Laravel's 422.
- **Controllers** do HTTP only: take the models bound from the path (a missing one is already a
  404, §5), call models and services, and return a Resource or `JsonResponse` with the right
  status. A failure is `throw new ApiException('PascalCaseCode', status, 'message')`.
- **Services** hold the work that isn't one model's: Danbooru, the duplicate scan, ingesting a
  file (fingerprint, metadata, thumbnails).
- **Resources** are the response shapes, unwrapped (no `{ data: … }`).
- **Jobs** take anything slow or per-file, on the database queue.

Prefer Eloquent and the query builder. The few raw fragments left (the random pick, the tag
search's `HAVING`, the implication walk) bind their values.

---

## 3. Running & building

### Backend, locally

```bash
cd backend
composer setup        # install, create .env from .env.example, generate APP_KEY
# set DB_DATABASE in .env to an absolute path to a copy of the gallery database
php artisan migrate
composer dev          # php artisan serve, on http://localhost:8000
```

Never point `DB_DATABASE` at a database another app is using. Fill in the `GALLERY_*` and
`DANBOORU_*` settings as `.env.example` describes. The Vite dev server proxies `/api` and
`/media` to `http://localhost` (`frontend/vite.config.ts`), so serve the backend there, or point
the proxy at `php artisan serve`'s port while you work.

The checks CI runs, from `backend/`:

```bash
composer lint            # Pint (per preset); `composer lint:fix` applies the fixes
composer analyse         # Larastan level 8 + strict and deprecation rules
composer rector:check    # current PHP and Laravel idioms, no dead code; `composer rector` applies
composer test            # Pest, including the contract replay
composer docs            # regenerate openapi.json (CI fails if the committed one is stale)
```

### Database

**Laravel migrations (`backend/database/migrations/`) are the schema.** The baseline,
`0001_01_01_000000_create_gallery_tables`, creates each gallery table only where it is missing:
on a fresh database it builds the schema, and on the live one (built by the Slim app's Phinx
migrations) it does nothing. Change the schema by **adding a migration**; never edit one that has
run on the droplet.

### Frontend
```bash
cd frontend
npm install
npm run dev        # Vite dev server on :5173, proxies /api and /media to http://localhost
npm run build      # vue-tsc type-check + vite build → outputs to frontend/dist
npm run preview
```
The production app is **the built `dist/`**, copied into `backend/public/` (its `index.html` into
`resources/spa/`) and served by Apache and the SPA shell; there is no Node server in prod.

### Background work: commands, queue and schedule

Artisan commands, run from the app folder (on the droplet as `www-data`:
`sudo -u www-data php artisan …`):

```bash
php artisan gallery:ingest [--import-tags]   # queue the files dropped into the media folder; remove items whose files are gone
php artisan gallery:import-tags [id]         # queue a Danbooru import for every item, or one
php artisan gallery:regenerate thumbnails    # or fingerprints, or metadata: queue a rebuild of every item from its file
php artisan gallery:dupes                    # scan for duplicates → dupes/dupes-YYYY-MM-DD.json
php artisan gallery:dupes --tune --max-distance=8 --max-region-diff=off  # calibrate (see §9)
```

- **The queue** is the `database` driver. On the droplet the systemd unit `gallery-queue` runs
  `php artisan queue:work --tries=3 --max-time=3600` as `www-data` and restarts by itself; every
  deploy sends `queue:restart` so it picks up new code. Jobs: `ProcessNewMedia` (one ingested file,
  unique per name), `ImportDanbooruTags` (rate-limited to 30 a minute, retried for a day),
  `RebuildFromFile`.
- **The schedule** (`routes/console.php`) runs `gallery:ingest` at midnight and
  `sanctum:prune-expired` daily, in `SCHEDULE_TIMEZONE` (UTC on the droplet).
  `/etc/cron.d/gallery-scheduler` runs `php artisan schedule:run` every minute as `www-data`.
- `gallery:ingest` refuses to remove anything when none of the gallery's files are in
  `media/full`, which is what a wrong `GALLERY_MEDIA_PATH` looks like.

### Deploy (to the DigitalOcean droplet)
```powershell
./scripts/deploy.ps1            # build the SPA → pack the code → upload → composer install → migrate → optimize
./scripts/deploy.ps1 -SkipBuild # redeploy the existing frontend/dist
```
`scripts/deploy.ps1` (PuTTY pscp/plink; host, user, key and app folder come from the untracked
`scripts/deploy.config.ps1`) ships **code only**. It never ships `.env`, `storage/`, `vendor/`, the
data or the backups. On the host it:

1. backs up the current code to `.deploy-backup/`, restored automatically if a later step fails;
2. replaces the code, with the code read-only to everyone but its owner;
3. runs `composer install --no-dev`;
4. snapshots the database (`VACUUM INTO`, see §10), then runs `php artisan migrate --force`;
5. runs `storage:link`, `optimize --except=views` and `queue:restart`;
6. gives the code, `vendor/` and `storage/` to `www-data`;
7. health-checks `/api/media/count`.

It uses only two SSH connections, to respect the droplet's `ufw limit ssh`.

Everything for the gallery lives in **`/var/www/gallery.mathdad.me/`**, as every site in
`/var/www` lives in a folder named for its domain:

| Path | Holds |
| --- | --- |
| `app/`, `bootstrap/`, `config/`, `database/`, `resources/`, `routes/`, `vendor/`, … | The code, replaced by each deploy |
| `public/` | Apache's DocumentRoot, the only part served (both gallery vhosts) |
| `.env`, `storage/` | Settings and Laravel's runtime files, kept across deploys |
| `db/`, `media/`, `dupes/` | The data: `gallery.db` (+ `.backups/` snapshots), the media and the ingest folder, scan reports |
| `backups/` | The pre-cutover backup, `ROLLBACK.txt`, the vhost files for a rollback; root-only |
| `slim-rollback/` | The Slim app as it last ran, until Phase 8 deletes it |

### Config (`backend/.env`, gitignored; documented in `backend/.env.example`)

Laravel's own keys (`APP_KEY`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, `LOG_*`,
`QUEUE_CONNECTION=database`, `CACHE_STORE=file`, `DB_DATABASE`) plus the gallery's, which
`config/gallery.php` reads:

```
GALLERY_ADMIN_PASSWORD     # admin password; login is refused while it is empty
GALLERY_URL                # public base URL, for Danbooru's IQDB fallback
GALLERY_ALLOWED_ORIGINS    # comma-separated CORS + CSRF origin allowlist
GALLERY_MEDIA_PATH         # media folder (full/, thumbs/, the ingest folder); default storage/app/media
GALLERY_DUPES_PATH         # duplicate-scan reports; default storage/app/dupes
DANBOORU_LOGIN             # Danbooru API username
DANBOORU_API_KEY           # Danbooru API key
DANBOORU_USER_ID           # the account's numeric ID, sent in the User-Agent; Danbooru's firewall blocks clients without it
FONTAWESOME_KIT_ID         # FA kit id (injected into <head> by the SPA shell)
DUPLICATE_MAX_DISTANCE     # duplicate scan: largest Hamming distance reported, 0-16 (default 6)
DUPLICATE_MAX_REGION_DIFF  # duplicate scan: largest worst-region difference, 0-255 or off (default 36)
SCHEDULE_TIMEZONE          # the schedule's timezone (UTC on the droplet)
```

Real environment variables take precedence over `.env`. The gallery's values are read raw, so a
password of `null` or `true` stays a string. **The production config is cached** (`optimize`), so
a `.env` change takes effect only after the next deploy, or `php artisan optimize --except=views`
as `www-data`.

---

## 4. Data model (SQLite)

```
media(media_id PK, media_type['image'|'video'], file_name UNIQUE, file_time, hash[MD5], bits_fingerprint,
      width, height, duration[secs, videos], file_size[bytes])   # metadata extracted at ingest
tag_categories(category_id PK, category_name UNIQUE COLLATE NOCASE, color[tag hue], description, sort_order)
tags(tag_id PK, category_id FK→tag_categories, tag_name UNIQUE COLLATE NOCASE)
media_tags(media_id FK, tag_id FK)              # junction, PK(media_id, tag_id)
tag_implications(tag_id FK, implied_tag_id FK)  # PK(tag_id, implied_tag_id)
danbooru_category_map(danbooru_category_id PK, danbooru_category_name, gallery_category_id FK)
danbooru_tag_map(id PK, danbooru_tag UNIQUE, gallery_tag)
dismissed_duplicates(media_id_1, media_id_2, dismissed_at)  # PK(id1,id2), stored with id1<id2

users                                           # one row: the admin the tokens belong to
personal_access_tokens                          # Sanctum: login tokens, stored hashed, valid 24h
jobs · job_batches · failed_jobs                # the database queue
migrations                                      # Laravel's migration log
```

The live database also still holds the Slim app's `auth_tokens`, `rate_limits` and
`phinx_migrations` until Phase 8 drops them. Rate limits now live in the cache, not a table.

Key indexes (in the baseline migration): `media(hash)`, `media(file_time, media_id)`,
`media(media_type)`, `media_tags(tag_id, media_id)` (reverse index for tag search),
`tags(category_id)`.

Notes:
- **Metadata** (`width`, `height`, `duration`, `file_size`) is read at ingest by
  `Services/Media/MediaMetadata` (images: `getimagesize`; videos and animated GIFs: `ffprobe`),
  through `MediaIngest`, so uploads and the ingest both fill it. Backfill with
  `php artisan gallery:regenerate metadata`. `duration` is 0 for still images.
- **`media_type='video'` includes animated GIFs** (more than one Graphic Control Extension
  header). Static GIFs are `'image'`. See `Media::typeOf()` and `Media::isAnimatedGif()`.
- Only **images** get a `bits_fingerprint` (perceptual hash); videos don't, so duplicate detection
  is image-only.
- `tag_name` and `category_name` are **case-insensitive** (`COLLATE NOCASE`).
- Tag deletion can **migrate** usages to another tag first (`Tag::moveMediaTo()`, in a transaction).
- Foreign keys use `ON DELETE CASCADE` and are enforced on every connection
  (`config/database.php`), so deleting a media row removes its `media_tags`, and so on.

---

## 5. API surface (hybrid REST-RPC, all under `/api`)

The **authoritative contract** is the generated OpenAPI 3.1 spec (`backend/openapi.json`,
`composer docs`), served at **`/api/openapi.json`** and browsable via **Scalar** at
**`/api/docs`**. Every operation has a readable `operationId` (`listMedia`, `uploadMedia`, …).
Below is the shape; 🔓 = public, 🔒 = bearer token required.

REST-RPC conventions: reads → `200`, create → `201` (created resource), update → `200`
(updated resource), delete → `204`, RPC action/batch → `200`. Non-CRUD state changes are
`POST …/{verb}`.

- **System / Auth:** `GET /version`, `GET /openapi.json`, `GET /docs` (🔓); `POST /auth/login` (🔓),
  `DELETE /auth/token` (🔒 log out: revokes the token sent).
- **Media (`/media`):** path-based listings (cached) — `GET /media/page/{page}[/{per_page}]`,
  `GET /media/untagged/{page}[/{per_page}]`, `GET /media/with-tags/{tag_list}/{page}[/{per_page}]`
  (`tag_list` comma-sep, leading `-` excludes), each with the `next_cursor` to go on from;
  `GET /media?cursor=…[&per_page&tags|untagged=1]` (the items after a cursor, for infinite scroll);
  `GET /media/{id}[?include=tags]`, `GET /media/random`, `GET /media/count`,
  `GET /media/tag-imports?ids=…` (how queued Danbooru lookups went), `POST /media/by-ids`
  (🔓 batch read); `POST /media` (🔒 multipart upload = create → 201; thumbnails and, with
  `fetch_tags`, Danbooru lookups are queued), `POST /media/bulk-delete` (🔒),
  `DELETE /media/{id}` (🔒 → 204). Media-scoped tags: `GET /media/{id}/tags` (🔓),
  **`PATCH /media/{id}/tags`** and **`DELETE /media/{id}/tags/{tag_id}`** (🔓 — anyone may tag, see §7),
  `POST /media/{id}/danbooru-tags` (🔒).
- **Tags (`/tags`):** `GET /tags` (every tag; or `?search=…&limit&exclude` for the best matches,
  exact then prefix then containing; or `?names=…` / `?ids=…` lookups), `GET /tags/display` (a
  page: `page`, `per_page`, `search`, `sort`, `direction`, `ids`), `GET /tags/{id}` (🔓);
  `POST /tags` (→201), `PATCH|PUT /tags/{id}`, `DELETE /tags/{id}`,
  `DELETE /tags/{id}/migrate-to/{target_id}` (migrate then delete), `POST /tags/{id}/migrate`
  (migrate, keeping the source tag) (🔒).
- **Tag categories (`/tag-categories`):** `GET` (🔓); `POST` (→201), `PATCH|PUT/DELETE /{id}` (🔒).
- **Tag implications (`/tag-implications`):** `GET` (🔓); `POST` (→201),
  `DELETE /{tag_id}/{implied_tag_id}` (🔒).
- **Danbooru (`/danbooru`):** `GET` mappings (🔓); `POST/PUT/DELETE` on
  `category-mappings[/{danbooru_category_id}]` and `tag-mappings[/{id}]` (🔒).
- **Duplicates (`/duplicates`):** `GET /report` (🔓), `POST /scan`, `POST /dismissals` (🔒).

### Response conventions

- Reads return the resource or collection (a Resource, unwrapped); creates `201` + resource;
  updates `200` + resource; deletes `204`. An update changes only the fields it sends, by `PATCH`
  or `PUT`.
- **Path ids are bound to models** (`Route::pattern` + `Route::model` in `AppServiceProvider`, one
  line per parameter name): only digits match a route, and a missing record is a 404 with its
  resource's code (`MediaNotFound`, `TagNotFound`, `CategoryNotFound`, `MappingNotFound`), mapped
  from the model in `ApiExceptionRenderer`. A new `{…_id}` parameter needs its line there.
- Every error under `/api` is `{ "error": "PascalCaseCode", "message": "human readable" }` with its
  status. The app raises them as `throw new ApiException('CodeName', 4xx, 'message')`.
  `ApiExceptionRenderer` gives the framework's failures the same shape: an unknown path is
  `404 NotFound`, a wrong method `405 MethodNotAllowed` (with `Allow`), anything uncaught
  `500 ServerError`. Only 5xx errors are logged.
- A failed validation is the first broken field's `error` and `message` (each endpoint's codes,
  from its Form Request's `errorCodes()`), plus `errors`: every broken field's messages, by field.
  `getFieldError(e, 'tag_name')` reads them on the frontend.
- Danbooru failures: `429 DanbooruRateLimited`, `500 DanbooruAuthFailed` (the API refused the
  credentials) or `DanbooruNotConfigured`, and `503 DanbooruUnreachable`, `DanbooruUnavailable` or
  `DanbooruBlocked` (its firewall refused the server; the message says why, with Cloudflare's Ray ID).
  An upload with `fetch_tags` reports a failed lookup on the file as `tags_error`.
- Cached GETs add `X-Cache: HIT|MISS`. Rate-limit info is in `X-RateLimit-Remaining` / `Retry-After`.
- The public reads go through `cache.headers:no_cache;private;etag`: an `ETag`, and `304` for a
  matching `If-None-Match`. A new public GET goes inside that group in `routes/api.php`; the docs,
  the spec and `/media/random` stay outside it.
- Every action and schema is annotated with `#[OAS\*]` (swagger-php spec attributes,
  `use OpenApi\Spec as OAS`); **regenerate `openapi.json` (`composer docs`) and the frontend
  types (`npm run gen:types`) after any contract change**. CI fails if they drift.

---

## 6. Middleware stack (order matters; set in `bootstrap/app.php`)

1. **`SecurityHeaders`** (global, first): `X-Frame-Options: SAMEORIGIN` and
   `X-Content-Type-Options: nosniff` on every response, the SPA shell's included.
2. **CORS** (Laravel's `HandleCors`, global): origins from `GALLERY_ALLOWED_ORIGINS`; a preflight
   is answered `204` before anything else checks it.
3. **`VerifyOrigin`** (global): the CSRF check for `/api`. A POST, PUT, PATCH or DELETE is refused
   (`403 ForbiddenOrigin`) unless its `Origin`, or failing that its `Referer`, is allowed; a write
   with **neither** is refused too (it's a client working around CORS, such as curl). It runs
   before the rate limiter and authentication, so a refused request is neither counted nor told
   whether its token was good.
4. **`throttle:api`** (the `api` group): 120 requests a minute per IP, then
   `429 RateLimitExceeded` with `retry_after`. It runs before authentication (a priority list in
   `bootstrap/app.php`), so every request counts, signed in or not.
5. **`auth:sanctum`** on the protected group in `routes/api.php`: no valid token is
   `401 Unauthorized` as `{ error, message }`; there is no login page to redirect to.

The `web` group is empty: the SPA shell keeps no session and sets no cookies. `TrimStrings` and
`ConvertEmptyStringsToNull` are removed, so a password or tag name arrives exactly as sent.

---

## 7. Security model (and the one intentional exception)

Keep these invariants when editing auth or routes:

- **Every state-changing route needs a bearer token**: it sits in the `auth:sanctum` group in
  `routes/api.php`. If you add a write route, put it in that group.
- **The public writes**, deliberately outside the group, each its own route: `PATCH /media/{id}/tags`
  and `DELETE /media/{id}/tags/{tag_id}` (anyone may tag media — an *intentional* design choice), and
  `POST /media/by-ids` (a read that uses POST only to carry a large id list). Being exact routes, a
  public sub-route can't widen a protected one (`DELETE /media/{id}` stays 🔒). Don't add to them
  lightly. They still pass the origin check.
- **CSRF**: writes are refused unless `Origin` (or, failing that, `Referer`) is in
  `GALLERY_ALLOWED_ORIGINS`; a write with neither header is refused.
- **Login**: refused entirely while `GALLERY_ADMIN_PASSWORD` is empty (so no default can grant
  access); throttled to 10 attempts / 5 minutes per IP (its own limiter) on top of the global one;
  the password is compared with `hash_equals()`.
- **Tokens**: Sanctum tokens on the one admin user (`User::admin()`): 64 random hex characters,
  **stored as a SHA-256 hash**, valid 24 hours (`sanctum.expiration`), pruned daily. Sent as
  `Authorization: Bearer …`; the frontend keeps it in `sessionStorage` (`useApi.ts`).
- **Only `public/` is served.** `.env`, the code, the data folders, `backups/` and
  `slim-rollback/` sit beside it, out of reach; an unknown path gets the SPA page, never a file.
  Never point a vhost at the app folder itself.

---

## 8. Frontend conventions

- **Vue 3 `<script setup lang="ts">` + Composition API only.** No Options API, no class components.
- **State**: Pinia stores (`stores/`). `gallery` holds `knownTags`, `categories`, `totalMedia`,
  `blurThumbnails` (persisted to `localStorage`), and `lastViewedItemIds` (used for prev/next
  navigation on the media detail page). `favorites` is a `Set<number>` persisted to `localStorage`.
  `toast` drives `ToastContainer`.
- **The app never loads every tag.** There are thousands. `knownTags` holds the tags seen so far
  (by id), from searches and lookups: a picker searches the server (`store.searchTags`, or
  `useTagSearch` for a plain input), and a chip or a breadcrumb looks its tag up with
  `tagById`/`tagByName` after `loadTagsById`/`loadTagsByName`. The Tags page pages, searches and
  sorts on the server (`/tags/display`).
- **Feedback has exactly two homes, and a message belongs to one of them.** A *toast* (`toast`
  store → `ToastContainer`) reports the outcome of a finished action, globally and transiently. An
  *alert* (`AppAlert`) states a condition in the place it applies to and stays there while you act
  on it — a failure inside the dialog that caused it, a warning on one section of a page. If the
  same wording would work in either place, it is a toast, so it is reported once; the bug this
  replaced was a dialog showing a green banner while an identical toast slid in beside it. Bulma's
  `.notification` is intentionally left unstyled so ad-hoc banners cannot creep back. Field
  validation is neither: it sits beside its input on `.help.is-danger`.
- **Keep alerts and toasts looking unalike.** They must be tellable apart at a glance, since only
  one of them disappears by itself. A toast is an object *above* the page: neutral `--surface-1`,
  drop shadow, 3px severity stripe, close button. An alert is *in* the page: flat, no shadow, no
  border, no stripe, washed with `--<severity>-soft`. Severity rides the wash and the icon, never
  the words — `--danger` on a washed background measures 4.11:1, under the 4.5 that 14px text must
  meet, so alert text stays on `--text-1`.
- **The toast viewport is fixed bottom-right at `z-index: 64`** — above dialogs (60/61), below the
  lightbox (70/71). It sets `pointer-events: none` with `pointer-events: auto` on each toast:
  Reka's modal Dialog puts `pointer-events: none` on `<body>` while open, which otherwise leaves
  Dismiss and Undo dead on any toast raised from inside a dialog, and without the `none` on the
  viewport its mostly-empty rectangle would swallow clicks meant for the page behind it.
- **API calls** go through `composables/useApi.ts` (`get/post/put/patch/del/upload`), using paths from
  `api/endpoints.ts`. It auto-attaches the bearer token, throws a structured `ApiError`
  (`status`, `code`, `message`, and a validation's `fields`), clears the token on 401, and
  resolves `204`/empty bodies to
  `undefined`. Don't call `fetch` directly in components.
- **Types are generated** from the OpenAPI spec into `types/api.generated.ts` (`npm run gen:types`);
  import domain types from `types/index.ts` (friendly `Required<>` aliases), never the generated file.
- **Lint + format are shared** via the `@jwill89/eslint-config` git submodule at `frontend/eslint-config`
  (Vue + TS **strictTypeChecked** + Prettier); `prettier.config.js` re-exports it and
  `eslint.config.ts` spreads it. Run `git submodule update --init` after cloning. Rules every project
  should get change in the submodule; this project's own sit after the spread in `eslint.config.ts`:
  template accessibility (`eslint-plugin-vuejs-accessibility`), the Vue style-guide rules that hold
  the conventions here (`<script setup lang="ts">` only, no inline `style`, explicit button `type`,
  `useTemplateRef`), typescript-eslint's stylistic set, and a ban on calling `fetch` outside
  `useApi.ts`. A rule that doesn't fit one line is turned off at that line with the reason beside it.
  The stylistic rules are listed by name rather than spread from `typescript-eslint`: the shared
  config registers that plugin from the submodule's own `node_modules`, and ESLint refuses a second
  copy under the same name. Idioms: `void` fire-and-forget promises (`void router.push(...)`);
  `catch (e)` + the `getError*` helpers in `useApi.ts`; `_`-prefix unused vars.
- **Stylelint** (`stylelint.config.js`, `npm run lint:css`) runs `stylelint-config-standard` over
  `style.css` and the SFC style blocks, and holds the palette rule: no hex, and no `rgb()`/`hsl()`
  other than neutral white/black, outside the `:root` token blocks in `style.css` (fenced by
  `stylelint-disable`/`enable` comments). **Knip** (`knip.ts`, `npm run knip`) fails on unused
  files, exports and dependencies. Component specs mount a view with `@vue/test-utils` and fake
  the server by mocking `useApi` (`UploadView.spec.ts`).
- **Data-fetching composables**: `useGalleryData` (paginated lists), `useMediaTags` (detail + tag
  add/remove). Reuse them rather than re-implementing fetch logic.
- **Routing**: lazy-loaded views in `router/index.ts`; page title from `meta.title`.
  **Infinite scroll is a store preference** (`useGalleryStore().infiniteScroll`, persisted to
  `localStorage`, toggled from the navbar's view-mode select), *not* a URL param — `perPage=0` was
  the old sentinel and any `perPage < 1` is now coerced to 40. While scrolling, `GalleryView`
  mirrors your position into the URL via `router.replace`, and the reload watcher deliberately
  ignores page-only changes so that sync doesn't re-fetch. It starts from a numbered page and
  goes on with `GET /media?cursor=` from each batch's `next_cursor`, so items added meanwhile
  don't repeat ones above; scrolling up still loads numbered pages. The next batch is requested
  as soon as the one before it arrives, and reaching the sentinel uses that request.
- **Which navbar controls a route supports is declared on the route**, via `meta.showBlur` and
  `meta.showViewMode` (typed in `router/index.ts`). Add the flags when you add a route; don't
  reintroduce a chain of negations in `AppNavbar` — that's how the per-page select ended up live
  on `/favorites` (which has no pagination) and on the 404 page.
- **Styling**: a token layer in `style.css` (`--surface-*`, `--text-*`, `--border*`, `--t-*` type
  scale, `--sp-*` space scale) with Bulma component overrides written against those tokens — no raw
  hex outside the `:root` blocks. Chrome is deliberately neutral; there is no brand accent.
  **Three palettes are kept strictly apart**: chrome (greys), semantic (`--danger`/`--success`/
  `--warning`/`--info`, state only), and tag categories (`--tag-<hue>`, display only). Categories
  used to store Bulma state names — Artist was `danger` — so a tag chip and the delete button were
  the same red; migration `20260805000000` moved them to the `tag--<hue>` namespace. Never wire a
  category to a semantic colour. `constants/categories.ts` maps a category's `color` to
  `tag--<hue>` / `tag-text--<hue>`; keep its `VALID_COLORS` in sync with
  `TagCategory::COLORS` (backend).
- **Interactive primitives** come from [Reka UI](https://reka-ui.com). Wrappers live in
  `components/`: `AppDialog` (all modals; pass `destructive` for irreversible actions and it swaps
  to `AlertDialog`), `AppMenu`/`AppMenuItem`, `AppTooltip`, `AppLightbox`, plus `TagMultiSelect`
  (Combobox), `PaginationBar` (Pagination), `ToastContainer` (Toast) and the navbar's `Toolbar` +
  `Toggle`. Don't hand-roll a modal, menu or listbox — the hand-rolled ones had no focus trap, no
  Escape handling and no `aria-modal`.
- **⚠ Scoped styles cannot reach portalled content.** Reka renders Combobox, DropdownMenu, Tooltip
  and Toast content through a Teleport, and Vue has no element on this side of that boundary to
  stamp the `data-v-*` scope id onto — so every rule in a `<style scoped>` block silently fails to
  match and the surface renders transparent, unbordered and `z-index: auto`. It looks exactly like
  a z-index bug. **Styles for portalled surfaces go in `style.css`** (see the "Portalled overlay
  surfaces" section there). Dialog is the exception: its parts do carry the scope id.
- **`AppTooltip` needs a `TooltipProvider` ancestor** — one is mounted at the root in `App.vue`.
  Without it the context injection throws during `setup` and takes down the whole surrounding
  subtree, not just the tooltip.
- **Shared UI**: `PageHeader` (title + breadcrumb + meta + actions — use it in every view so page
  titles stop drifting between sizes), `EmptyState`, `StatRow`, `TagCategoryLegend`.
- **Thumbnails**: derive from `file_name` by stripping the extension →
  `/media/thumbs/<base>.webp` (1x) and `/media/thumbs/<base>@2x.webp` (2x, used in `srcset`).
- **Service worker** (`public/sw.js`): cache-first for thumbs/static assets, network-first for
  the media listings (`isMediaList()`: the numbered pages and `GET /api/media`, capped at 200
  entries), network-only for full-size media. Registered **in production only**
  (`import.meta.env.PROD` in `main.ts`) — a worker caching `/assets/*` in front of the Vite dev
  server makes local debugging depend on which copy it hands back. The app posts
  `PREFETCH_THUMBNAILS` to warm the *next* page (paged, `prefetchAdjacentPage`) or the *next
  batch* (infinite scroll, `prefetchNextBatch`) via `usePrefetch.ts`; keep both in `GalleryView`
  in sync. Bump `CACHE_VERSION` in `sw.js`
  when changing caching behavior — a stale worker keeps controlling the page until every tab for
  the origin closes, so a hard refresh will not replace it.
- **Infinite scroll's bottom sentinel needs a top-up loop.** `IntersectionObserver` only reports
  intersection *changes*, so on a viewport tall enough that a fresh batch doesn't push the sentinel
  back out of view, no second callback arrives and loading stalls. `loadNextBatch` keeps pulling
  while `sentinelInTriggerZone()` holds; that helper and the observer share `SENTINEL_MARGIN` so
  they can't disagree. Also keep layout reads (`getBoundingClientRect`) inside the scroll handler's
  `requestAnimationFrame` — the cards use `content-visibility: auto`, and reading layout on every
  scroll event pins the main thread hard enough that the observer never fires.
- **Never return 502 or 504 from the API.** The site is served through Cloudflare, which treats
  gateway-class statuses as "the origin is broken" and replaces the response with its own HTML
  error page. The JSON body — including `message` — is discarded, `parseApiError` then fails to
  parse it and falls back to `Request failed (HTTP 502)`, so the real reason never reaches the
  user. For upstream (Danbooru) failures use `429` for rate limits, `500` for credential/config
  problems and `503` for unreachable/erroring dependencies; those pass through untouched.

---

## 9. Caching & performance model

- **Response cache** (`App\Support\ResponseCache`, on the file cache store in
  `storage/framework/cache`): a busy GET wraps its body in
  `$this->cache->remember(CacheGroup::Media, $key, ResponseCache::TTL_SHORT, fn () => …)` and
  answers with `X-Cache: HIT|MISS`. TTLs: `TTL_SHORT=30s` (lists), `TTL_MEDIUM=60s`
  (totals/all-tags/implications).
- **Invalidation is group-based, and the models do it.** The file store has no cache tags, so
  each group's version number is part of its keys and `ResponseCache::bump()` moves it on. Model
  events bump: saving media bumps `Media`; deleting it, or changing its tags (`attachTags`,
  `removeTag`, `Tag::moveMediaTo`, all through `Media::tagsChanged()`), bumps `Media` and `Tags`;
  saving a tag or category bumps `Tags`, deleting a tag both; `Tag::imply`/`unimply` bump `Tags`.
  So a write from a request, a job or a command clears what it changes, as long as it goes through
  the models: a query-builder `update()`/`delete()` or a raw pivot write fires no event, and must
  call `bump()` itself. **If you add a cached GET, check that every write that affects it bumps its
  group**, or it serves stale data for up to the TTL. Deploys don't clear the cache: entries expire within a minute anyway, and a
  clear would also drop the rate limiter's counts and the queue's unique locks.
- **SQLite** runs with WAL, foreign keys on, `synchronous=NORMAL` and a 5 s busy timeout
  (`config/database.php`). One queue worker keeps writers few.
- **Request cost** (measured on the droplet, 2026-11): a cached read takes about 10 ms, the
  1.5 MB tag list about 13 ms. Laravel's boot adds 3–5 ms over the Slim app it replaced, which
  is lost in a request's trip through Cloudflare.
- **The duplicate scan is the heaviest operation** (`gallery:dupes`, or `POST /duplicates/scan`):
  LSH banding → Hamming filter → a comparison of each remaining pair's `@2x` thumbnails (not
  originals, to bound memory); it raises `memory_limit`/`max_execution_time` for the run.
  **Variants are the hard part.** This gallery holds many versions of one artwork that differ only
  in a small area (an expression, a detail), and those hash as close as distance 0 and score
  whole-image SSIM up to 0.97, so neither a tighter cutoff nor an SSIM floor can tell them from a
  re-saved copy (an SSIM floor was tried and dropped, 2026-11). `Services/Duplicates/RegionDifference`
  can: both thumbnails are resampled to 256², blurred to wash out compression noise, the overall
  colour offset removed, and the result is the mean difference of the *worst* 8×8 region on any
  channel. Calibrated on the droplet against the 26 pairs dismissed in the UI and synthetic
  re-saves: re-saves scored ≤ 32.7, variants ≥ 40.9; coarser regions overlapped, so keep them fine.
  **Tuning:** `DUPLICATE_MAX_DISTANCE` (default 6; re-saves landed 0–5 bits apart) is the Hamming
  cutoff and `DUPLICATE_MAX_REGION_DIFF` (default 36, `off` to disable) the region ceiling; a pair
  whose thumbnails can't be read is kept. The LSH band width is derived from the cutoff (cutoff + 1
  bands, so no pair within it can miss them all). Buckets of over 100 images are still skipped and
  counted as `skipped_buckets`; each can hide pairs, which is what caps the cutoff at 16.
  `gallery:dupes --tune` is the calibration run: it keeps dismissed pairs (flagged), prints a
  distance × region-difference table, and writes `dupes/tuning-<timestamp>.json`, so the report the
  Duplicates page reads (`dupes-*.json`) is untouched. Report matches are
  `[id1, id2, distance, ssim, region_diff]` (+ `dismissed` on a tuning run); the API reads the
  first four, so older reports still load.

---

## 10. Known traps & drift risks (read before editing)

- **The schema is the migrations.** Add a migration to change it; never edit one that has run on
  the droplet. `SchemaTest` checks that a fresh `migrate` builds the production schema the Slim app
  left (`tests/Fixtures/schema.sql`), so a migration that changes a gallery table also changes what
  that test expects — deliberately, in the same change.
- **The contract recordings are frozen at API 4.** `tests/Feature/Contract/ContractTest.php`
  replays all 191 of them (`tests/Fixtures/contract/`, format in its README), with
  `/api/version` pinned to the app and API versions they were made at. The recorder went with
  the Slim app, so a recording is edited by hand only to correct it. A deliberate change to what
  the API answers goes in `tests/Feature/Contract/divergences.php` with its reason: an expected
  response the replay checks instead, or, where API 5 replaced the behaviour, a `superseded`
  entry naming the feature test that covers it now (the replay skips it; a test checks the file
  exists). The replay ignores the fields API 5 added (`Replay::ADDED_FIELDS`: `errors`,
  `next_cursor`) when a recording lacks them. Record it in the plan's "Contract changes" or
  "API 5" section too.
- **PHP's upload limits fail quietly, so the upload page works within them.** `backend/public/.htaccess`
  sets `upload_max_filesize 100M` / `post_max_size 105M`, and `php.ini` keeps PHP's default
  `max_file_uploads = 20`. Past 20 files PHP drops the rest of a request, and past `post_max_size`
  the whole body, before the app sees it. So the upload page sends files in batches of at most
  20 files and 100 MB, and doesn't send a file over 100 MB at all (`constants/uploads.ts`, mirroring
  those settings: change both together). `UploadController::MAX_FILE_SIZE` still says 500 MB, but
  PHP's 100 MB applies first.
- **`ffmpeg` and `ffprobe` must be on the PATH** for thumbnails and video metadata (they run
  through Laravel's `Process`); without them the gallery shows its placeholder. **GD** (with WebP,
  ideally AVIF) is required for fingerprints and the duplicate scan's thumbnail comparison.
- **Danbooru's firewall wants the account in the User-Agent.** Requests are sent as
  `GalleryTagImporter/1.0; user #<DANBOORU_USER_ID>`; without the ID, Cloudflare blocks the
  server (`503 DanbooruBlocked`) before the API key is even checked. A 403 that isn't JSON is that
  block; the API's own refusals are JSON (`DanbooruAuthFailed`).
- **Settings come from `config()`, never `env()`.** Production runs on a cached config, where
  `env()` returns null outside `config/`. A new setting goes in `config/gallery.php` and
  `.env.example`, and on the droplet in `.env` followed by a deploy.
- **The queue worker runs old code until it restarts.** Deploys send `queue:restart`; after any
  change made outside a deploy, run it yourself. A job already queued is unserialized with the
  class as it is when it runs, so rename a job's properties with care.
- **CI runs lint, static analysis, tests and build.** GitHub Actions (`.github/workflows/ci.yml`)
  runs, on the backend, `composer audit` + `composer lint` (Pint) + `composer rector:check` +
  `composer analyse` (Larastan level 8 + strict and deprecation rules) + `composer docs` with
  `git diff --exit-code openapi.json` + `composer test` (Pest). On the frontend: `npm audit --omit=dev`
  + `npm run gen:types` with `git diff --exit-code src/types/api.generated.ts` + `npm run lint`
  (ESLint, type-checked, incl. accessibility) + `npm run lint:css` (Stylelint) + `npm run knip` +
  `npm run format:check` (Prettier) + `npm run build` (vue-tsc + Vite) + `npm run test` (Vitest),
  with coverage reported (no hard gate). A third job runs PSScriptAnalyzer over `scripts/*.ps1` with
  `PSScriptAnalyzerSettings.psd1`. The frontend job checks out submodules. Backend feature tests run
  on a fresh in-memory SQLite database per test (`RefreshDatabase`), with Danbooru (`Http::fake`),
  ffmpeg (`Process::fake`) and the queue faked where they're touched.
- **Deploys snapshot the database before migrating** — `scripts/deploy.ps1` writes
  `db/.backups/gallery-<timestamp>.db` via SQLite `VACUUM INTO` (keeping five) and aborts before
  migrating if the snapshot fails. Use `VACUUM INTO`, never `cp`: the connection runs in WAL mode,
  so a plain copy misses whatever is still in the `-wal` sidecar. The snapshot is deliberately
  **not** auto-restored by the failure trap — that trap fires on any non-zero exit, including
  after a migration has already succeeded, and reverting data could discard media uploaded during
  the deploy window; a failed migration prints the path and the restore command instead.
- **OpenAPI is swagger-php 7's spec attributes** (`use OpenApi\Spec as OAS;`). The document is built
  by `tests/Support/OpenApiDocument::builder()`, which both `composer docs` (`bin/openapi.php`) and
  `OpenApiSpecTest` use, so the two can't disagree about how it's built. That builder drops v7's
  `Properties` augmenter, which would publish every PHP property initialiser on a doc-only response
  class as a schema `default`; put a real default on the attribute instead. Give every operation an
  explicit, readable `operationId`. swagger-php 8 moves the spec classes from `OpenApi\Spec` to
  `OpenApi\Attributes`: one `use` line per file, and the package's Rector set handles it.
- **Fingerprints are only comparable with ones computed the same way.** Anything that changes how
  `Services/Fingerprinter` hashes (algorithm, library, image driver) needs
  `php artisan gallery:regenerate fingerprints` on the droplet right after the deploy, before the
  next scan or ingest: until its jobs finish, old and new fingerprints sit side by side and their
  distances mean nothing. Hashing is pinned to GD because the hash otherwise depends on whether
  Imagick is loaded. A fingerprint that can't be recomputed is cleared rather than kept, so a
  failure drops that image from the scan instead of leaving a stale-format hash in it.
- **`config.platform.php` in `backend/composer.json` mirrors the droplet's PHP** (8.5.12 as of
  2026-11), so Composer resolves for what production runs, not your machine. Raise it only after
  upgrading the droplet.
- **Line endings are LF, enforced by `.gitattributes` (`* text=auto eol=lf`).** Pint and Prettier
  fail or rewrite a CRLF file, so a Windows checkout without this produced lint errors in files
  nobody had touched. `eol=lf` overrides `core.autocrlf`, so no per-developer git config is
  required — do not "fix" a CRLF complaint by changing your local `core.autocrlf`. Converting a
  file's line endings does *not* fix line endings already escaped inside a generated artifact:
  `openapi.json` once carried `\r\n` inside descriptions taken from docblocks, and needed
  `composer docs` to clear (the `OpenApiSpecTest` catches this).
- **Do not upgrade to TypeScript 7 yet.** TS 7 is the native port and removed the `./lib/tsc`
  subpath from its package `exports`. `vue-tsc` resolves that path directly and exits with
  `ERR_PACKAGE_PATH_NOT_EXPORTED`, so the type-check (`npm run build`) cannot run at all — and
  `vue-tsc`'s `typescript: ">=5.0.0"` peer range does not express the incompatibility, so npm will
  install the combination without complaint. Still true of 3.3.14 (2026-11): its only addition is
  following `typescript` when it is aliased to `@typescript/typescript6`. Stay on `~6.0.3` until
  vue-tsc ships TS 7 support.
- **Version bumps are the release trigger.** CI tags and publishes `v<version>` from
  `frontend/package.json` using the matching `## [<version>]` section of `CHANGELOG.md` as the
  release body, so bump the version (`frontend/package.json` and `package-lock.json`, and
  `config/gallery.php`'s `version`, which `GET /api/version` serves) and write its section in the
  same commit. The **HTTP contract version is separate** — `App\OpenApi\OpenApiSpec::API_VERSION` —
  and only moves when the API surface changes.
- Release history lives in `CHANGELOG.md` (Keep a Changelog format). A historical
  `IMPROVEMENTS.md` also exists (gitignored) — old internal review notes, not a current audit;
  don't trust it as the state of the codebase.

---

## 11. How to add things (quick recipes)

**A new API endpoint**
1. Add the route to `routes/api.php`: public reads at the top, writes inside the `auth:sanctum`
   group (a public write outside it only deliberately, see §7).
2. For a write with a body, add a Form Request extending `ApiRequest`: `rules()`, `messages()` in
   the contract's wording, and `errorCodes()` for each rule's code and status.
3. Add the controller action: work through models and services, return a Resource (or
   `JsonResponse`) with the right status, raise failures as `ApiException`, and **annotate it with
   `#[OAS\Operation\Get/Post/…]`** and a readable `operationId` (+ any new response schema under
   `app/OpenApi/Schemas/`).
4. If it's a cached GET, use `$this->cache->remember()` (a public GET also goes in the
   `cache.headers` group for its ETag). A write through the models clears the cache by their
   events (§9); one that bypasses them calls `bump()` for the groups it changes.
5. Add a feature test (Pest) that goes through HTTP; fake Danbooru, ffmpeg or the queue if it
   reaches them.
6. Run `composer docs` (backend) and `npm run gen:types` (frontend); commit both regenerated files.

**A new media field**
A **migration** adding the column (and `SchemaTest`'s expectation), the `Media` model (`$fillable`,
casts), `MediaResource` (+ `#[OAS\Property]` on its schema), `MediaIngest`/`MediaMetadata` if it is
read at ingest (and `gallery:regenerate` to backfill), then regenerate the spec + types
(`composer docs`, `npm run gen:types`) so the frontend `Media` type picks it up.

**A new tag-category color**
Add to `TagCategory::COLORS` (backend) **and** `VALID_COLORS` in `constants/categories.ts`
(frontend) **and** define the CSS in `frontend/src/style.css`.

**A new background task**
A job class (`ShouldQueue`) dispatched where the work starts; if it runs on a timetable, one line
in `routes/console.php`. Writing through the models clears the cached reads it changes (§9).

**A new setting**
`config/gallery.php` (read raw, as the others are), `.env.example` with a comment, the
`GalleryConfigTest` expectation, and §3's list above. On the droplet, add it to `.env` and deploy.

**A new frontend page**
Create a view in `views/`, add a lazy route with `meta.title` in `router/index.ts`, fetch via
`useApi`/a composable, and link it from `AppNavbar.vue`.

---

## 12. Conventions checklist for any change

- [ ] Writes are validated by a Form Request extending `ApiRequest`; failures are `ApiException`s
      with a PascalCase code and a human message.
- [ ] The right status codes (`200`/`201`/`204`), and responses through Resources.
- [ ] Endpoints/schemas are `#[OAS\*]`-annotated with an `operationId`; `composer docs` +
      `npm run gen:types` re-run and the regenerated `openapi.json` / `api.generated.ts` committed (CI enforces).
- [ ] Writes go through the models so their events clear the cache (or `bump()` by hand, §9).
- [ ] New state-changing routes are in the `auth:sanctum` group (or public, consciously).
- [ ] Schema changes land in a **new migration**.
- [ ] Settings are read with `config()`, never `env()` outside `config/`.
- [ ] Slow or per-file work runs in a queued job.
- [ ] A feature test covers it; the contract replay stays green, and a deliberate contract change
      is in `divergences.php`.
- [ ] Frontend: typed (import from `types/`), `<script setup>`, API via `useApi` + `api/endpoints.ts`, no direct `fetch`.
- [ ] Bump `sw.js` `CACHE_VERSION` if caching/asset behavior changed.
- [ ] Keep the mirrored lists in sync: tag colors (`TagCategory::COLORS` ↔ `constants/categories.ts`),
      file types (`Media::IMAGE_EXTENSIONS`/`VIDEO_EXTENSIONS` ↔ `UploadView.vue`), and upload limits
      (`public/.htaccess` ↔ `constants/uploads.ts`).
