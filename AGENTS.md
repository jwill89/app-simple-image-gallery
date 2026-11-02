# AGENTS.md

Operational guide for AI coding agents (and humans) working in this repository.
Read this before making changes. It describes the architecture, conventions,
data model, commands, and the non-obvious gotchas that will bite you.

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
- A PWA-ish **service worker** for thumbnail/API caching and adjacent-page prefetch.

### Tech stack

| Layer | Tech |
|------|------|
| Backend | PHP **8.5** (typed constants, `match`, enums-style consts), [Slim 4](https://www.slimframework.com/) + PHP-DI |
| Database | **SQLite** (single file at `db/gallery.db`), WAL mode |
| Migrations | [Phinx](https://phinx.org/) (`db/migrations/`) — the single source of truth for the schema |
| Image/video tooling | `ffmpeg` (shelled out via `exec`), PHP GD, `intervention/imagehash` (perceptual hash, always on GD) |
| Logging | Monolog (rotating file, 14 days) |
| Frontend | **Vue 3** (`<script setup>` + Composition API), TypeScript, [Pinia](https://pinia.vuejs.org/), Vue Router |
| Build | **Vite** on Node **26+** (outputs to `frontend/dist`) |
| Styling | **Bulma 1.x** + custom `style.css` (extended color palette), FontAwesome (kit script) |
| Tests | **PHPUnit 13** (`backend/tests/`, in-memory SQLite) + **Vitest** (`frontend/src/__tests__/`, happy-dom) |

---

## 2. Repository layout

The repo is split into **`backend/`** (PHP) and **`frontend/`** (Vue), with a dev-only
**`scripts/`** at the root. The droplet's webroot is **flat** (project root ===
DocumentRoot): at deploy time `scripts/deploy.ps1` flattens `backend/*` and the built
`frontend/dist` into one payload that mirrors the live layout. All PHP resolves paths
via `__DIR__`/`chdir(__DIR__ . '/..')`, so `backend/` *is* the app root in both places.

```
/
├── backend/                   # PHP app — flattened onto the droplet webroot at deploy time
│   ├── index.php              # SPA front controller: injects OG/Twitter meta + FA kit, serves dist/index.html
│   ├── .htaccess              # Apache rewrites: dist assets → real files → /api → index.php fallback
│   ├── composer.json          # PSR-4: Gallery\ → includes/Gallery, Routes\ → api/Routes
│   ├── phinx.php              # Phinx config (sqlite, db/gallery.db)
│   ├── phpunit.xml            # PHPUnit 13 config (tests/Unit; coverage source)
│   ├── .php-cs-fixer.dist.php # coding standard: PER-CS 3.0 (PSR-12's successor) via PHP CS Fixer
│   ├── phpstan.neon           # PHPStan level 8 + strict/deprecation/PHPUnit rules
│   ├── rector.php             # PHP 8.5 idioms + dead-code removal (`composer rector:check` in CI)
│   ├── composer-unused.php · composer-require-checker.json  # `composer deps:check` config
│   ├── openapi.json           # generated (committed) — via `composer docs`; served at /api/openapi.json
│   ├── bin/openapi.php        # `composer docs`: builds openapi.json (builder shared with OpenApiSpecTest)
│   │
│   ├── api/
│   │   ├── .htaccess          # Routes everything to api/index.php
│   │   ├── index.php          # Slim bootstrap: middleware stack + ALL route definitions
│   │   ├── dependencies.php   # PHP-DI definitions: provides PDO, autowires Repository/Collection→Controller
│   │   └── Routes/
│   │       ├── OpenApiSpec.php          # global #[OAS\Info/Server/Security\Scheme/Tag] metadata
│   │       └── Internal/                # Controllers (namespace Routes\Internal), all #[OAS\*]-annotated
│   │           ├── AbstractController.php     # success()/created()/noContent()/error()/cachedSuccess()
│   │           ├── SystemController.php       # /version, /openapi.json, /docs (Scalar)
│   │           ├── AuthController.php         # /auth/login
│   │           ├── MediaController.php        # /media/*  (list/read/upload/delete + media-scoped tags)
│   │           ├── TagController.php          # /tags/*   (tag CRUD + migrate)
│   │           ├── TagCategoryController.php  # /tag-categories/*
│   │           ├── TagImplicationController.php # /tag-implications/*
│   │           ├── DanbooruController.php     # /danbooru/{category,tag}-mappings
│   │           ├── UploadController.php       # POST /media (multipart create)
│   │           └── DuplicatesController.php   # /duplicates/*
│   │
│   ├── includes/Gallery/      # Domain layer (namespace Gallery\…)
│   │   ├── Core/              # Configuration (holds VERSION), DatabaseConnection, Logger, RateLimiter,
│   │   │                      # ResponseCache, DanbooruTagger, DuplicateScanner, MediaThumbnail
│   │   ├── Collection/        # "Service"/use-case layer (MediaCollection, …)
│   │   ├── Repository/        # Data-access (TagRepository, TagCategoryRepository, DanbooruRulesRepository)
│   │   ├── Storage/           # SQL data-access layer (MediaStorage)
│   │   └── Structure/         # Plain data objects (Media, Tag, TagCategory) — JsonSerializable, #[OAS\Schema]
│   │       └── Api/           # Doc-only #[OAS\Schema] classes for composite responses (MediaPage, …)
│   │
│   ├── db/
│   │   ├── setup.php          # CLI bootstrap: ensures db/gallery.db exists, then runs Phinx migrations
│   │   ├── migrations/        # Phinx migrations (the schema source of truth)
│   │   └── gallery.db         # SQLite database (gitignored)
│   │
│   ├── scripts/               # CLI cron/maintenance scripts (gitignored). cron.php = ingest pipeline,
│   │                          # dupes.php = duplicate scan, tag_imports.php, regenerate-*.php
│   │
│   ├── tests/                 # PHPUnit 13 suite
│   │   ├── Support/           # DatabaseTestCase + schema.sql (fresh in-memory SQLite per test)
│   │   └── Unit/              # by layer: Structure/, Core/, Storage/
│   │
│   ├── vendor/                # Composer deps (gitignored, regenerated on the host)
│   ├── cache/api/             # File-based response cache (gitignored)
│   ├── dupes/                 # Duplicate scan JSON reports (gitignored)
│   ├── logs/                  # Monolog output (gitignored)
│   ├── .env                   # Config (gitignored); template committed as .env.example
│   └── media/
│       ├── (root)             # cron.php INPUT folder: drop new files here for ingestion
│       ├── full/              # canonical full-size files (served directly)
│       └── thumbs/            # generated WebP thumbnails: <base>.webp (200px) and <base>@2x.webp (400px)
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
│   │   ├── constants/categories.ts  # color → CSS class helpers (reads store)
│   │   ├── __tests__/         # Vitest specs (*.spec.ts): useApi, categories, stores
│   │   └── style.css          # Custom styles + extended Bulma color palette
│   ├── public/sw.js           # Service worker (caching + prefetch)
│   └── dist/                  # Vite build output (gitignored) — index.php serves dist/index.html
│
├── .github/workflows/ci.yml   # CI: lint, static analysis, tests + build per side; PSScriptAnalyzer on scripts/
├── .editorconfig              # LF, UTF-8, 4 spaces (2 for frontend files, YAML, Markdown)
├── PSScriptAnalyzerSettings.psd1  # PowerShell lint settings for scripts/
├── README.md · CONTRIBUTING.md · CHANGELOG.md · LICENSE
└── scripts/                   # Dev-only: deploy.ps1 (PuTTY pscp/plink → droplet) + config template
```

### Layering convention (backend)

`Controller → Collection → Storage → DatabaseConnection (PDO)`

- **Structure** (`Media`, `Tag`, `TagCategory`): dumb data holders extending `AbstractStructure`
  (which provides `jsonSerialize()` via reflection). Properties are **private** and hydrated by
  PDO `FETCH_CLASS`. Fluent setters return `$this`.
- **Storage**: the only place raw SQL lives. One class per table-group. Always use PDO prepared
  statements / bound params.
- **Collection**: thin domain/use-case wrapper over Storage; also where filesystem side effects
  live (e.g. `MediaCollection::save()` writes the thumbnail + fingerprint; `delete()` unlinks files).
- **Controller**: HTTP concerns only — parse/validate input, call collections, shape responses
  via `$this->success()` / `$this->error()` / `$this->cachedSuccess()`.

When adding a feature, follow this chain. Do **not** put SQL in controllers or collections.

---

## 3. Running & building

### Backend (Apache + PHP 8.5)
The app expects to be served by Apache with `mod_rewrite`. The document root is the PHP app root —
**`backend/`** in this repo (the deploy flattens it into the droplet webroot); `.htaccess` serves
`dist/*` assets, then real files (`media/full`, `media/thumbs`), routes `/api` to the Slim app, and
falls everything else back to `index.php` (which injects OG tags and serves the SPA shell).

```bash
cd backend
composer install              # install PHP deps into backend/vendor/
composer lint                 # PER-CS 3.0 (PHP CS Fixer); `composer lint:fix` applies the fixes
composer analyse              # PHPStan level 8 + strict and deprecation rules
composer rector:check         # current PHP idioms, no dead code; `composer rector` applies
composer deps:check           # every package used is declared, every one declared is used
```

### Database
**Phinx migrations (`backend/db/migrations/`) are the single source of truth** for schema, indexes,
and seeds. Run from `backend/`:
```bash
php vendor/bin/phinx migrate    # apply migrations
php db/setup.php                # convenience wrapper: ensures db/gallery.db exists, then migrates
```
`db/setup.php` no longer contains hand-written `CREATE TABLE`s — it just creates the SQLite file if
missing and runs Phinx. To change the schema, **add a migration** (`php vendor/bin/phinx create …`).

### Frontend
```bash
cd frontend
npm install
npm run dev        # Vite dev server on :5173, proxies /api and /media to http://localhost
npm run build      # vue-tsc type-check + vite build → outputs to frontend/dist
npm run preview
```
The production app is **the built `dist/`** served via `index.php`; there is no Node server in prod.

### CLI maintenance (in `backend/scripts/`, run from anywhere — they `chdir` to the app root)
```bash
php backend/scripts/cron.php          # ingest new files from media/ → media/full/, build thumbs+fingerprints, prune orphans
php backend/scripts/dupes.php         # run the duplicate scanner → writes dupes/dupes-YYYY-MM-DD.json
php backend/scripts/dupes.php --tune --max-distance=8 --max-region-diff=off  # calibrate (see §9)
php backend/scripts/regenerate-thumbnails.php
php backend/scripts/regenerate-fingerprints.php   # after any change to how fingerprints are computed (see §10)
php backend/scripts/regenerate-metadata.php   # backfill width/height/duration/file_size on existing rows
php backend/scripts/tag_imports.php   # bulk Danbooru tag import
```
All CLI scripts have a `PHP_SAPI !== 'cli'` guard and refuse to run over HTTP.

### Deploy (to the DigitalOcean droplet)
```powershell
./scripts/deploy.ps1            # build SPA → tar backend/ + frontend/dist → upload → composer install → migrate
./scripts/deploy.ps1 -SkipBuild # redeploy existing frontend/dist
```
`scripts/deploy.ps1` (PuTTY pscp/plink) **flattens `backend/*` + `frontend/dist`** onto the flat
webroot and ships **code only** (never `db/gallery.db`, `.env`, `vendor/`, or `media/`), then on the
host runs `composer install` (with dev deps — Phinx is a dev dependency) and `php db/setup.php`
(which baselines a legacy pre-Phinx DB, then applies pending migrations), restores `www-data`
ownership, and clears `cache/api/`. Keeps a `.deploy-backup/` for rollback. Uses only two SSH
connections to respect the droplet's `ufw limit ssh`. The webroot is `/var/www/gallery.mathdad.me`
and *is* the app root (project root === DocumentRoot).

### Config (`backend/.env` — gitignored; template in `backend/.env.example`)
Keys (resolved by `Gallery\Core\Configuration`):
```
GALLERY_ADMIN_PASSWORD     # admin password; DEFAULTS TO 'changeme' if unset (⚠)
GALLERY_URL                # public base URL, required for Danbooru IQDB fallback
GALLERY_ALLOWED_ORIGINS    # comma-separated CORS allowlist
DANBOORU_LOGIN             # Danbooru API username
DANBOORU_API_KEY           # Danbooru API key
FONTAWESOME_KIT_ID         # FA kit id (injected into <head> by index.php)
DUPLICATE_MAX_DISTANCE     # duplicate scan: largest Hamming distance reported, 0-16 (default 6)
DUPLICATE_MAX_REGION_DIFF  # duplicate scan: largest worst-region difference, 0-255 or off (default 36)
```
Explicit OS/Docker env vars take precedence over `.env`. `Configuration` also understands Apache
`REDIRECT_`-prefixed vars from mod_rewrite.

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
auth_tokens(token PK, created_at)               # bearer tokens, valid 24h
rate_limits(ip, requested_at)                   # sliding-window limiter
```

Key indexes (defined in `db/migrations/`): `media(hash)`, `media(file_time DESC, media_id DESC)`,
`media(media_type)`, `media_tags(tag_id, media_id)` (reverse index for tag search),
`tags(category_id)`, `rate_limits(ip, requested_at)`, `auth_tokens(created_at)`.

Notes:
- **Metadata** (`width`, `height`, `duration`, `file_size`) is extracted at ingest by
  `Core/MediaMetadata` (images: `getimagesize`; videos/animated GIFs: `ffprobe`) inside
  `MediaCollection::save()`, so both the upload and cron paths populate it. Backfill existing rows
  with `php backend/scripts/regenerate-metadata.php`. `duration` is 0 for still images.
- **`media_type='video'` includes animated GIFs** (detected by counting Graphic Control Extension
  headers). Static GIFs are `'image'`. See `MediaCollection::detectMediaType()` and `isAnimatedGif()`.
- Only **images** get a `bits_fingerprint` (perceptual hash); videos don't, so duplicate detection
  is image-only.
- `tag_name` and category name/short are **case-insensitive** (`COLLATE NOCASE`).
- Tag deletion can **migrate** usages to another tag first (`migrateTag`, wrapped in a transaction).
- Foreign keys use `ON DELETE CASCADE`, and `PRAGMA foreign_keys=ON` is set per connection — deleting
  a media row removes its `media_tags`, etc.

---

## 5. API surface (hybrid REST-RPC, all under `/api`)

The **authoritative contract** is the generated OpenAPI 3.1 spec
(`api/openapi.json`, `composer docs`), served at **`/api/openapi.json`** and browsable
via **Scalar** at **`/api/docs`**. `backend/API.md` is the human overview. Below is the
shape; 🔓 = public, 🔒 = bearer token required.

REST-RPC conventions: reads → `200`, create → `201` (created resource), update → `200`
(updated resource), delete → `204`, RPC action/batch → `200`. Non-CRUD state changes are
`POST …/{verb}`.

- **System / Auth:** `GET /version`, `GET /openapi.json`, `GET /docs` (🔓); `POST /auth/login` (🔓).
- **Media (`/media`):** path-based listings (cached) — `GET /media/page/{page}[/{per_page}]`,
  `GET /media/untagged/{page}[/{per_page}]`, `GET /media/with-tags/{tag_list}/{page}[/{per_page}]`
  (`tag_list` comma-sep, leading `-` excludes); `GET /media/{id}`, `GET /media/random`,
  `GET /media/count`, `POST /media/by-ids` (🔓 batch read);
  `POST /media` (🔒 multipart upload = create → 201), `POST /media/bulk-delete` (🔒),
  `DELETE /media/{id}` (🔒 → 204). Media-scoped tags: `GET /media/{id}/tags` (🔓),
  **`PATCH /media/{id}/tags`** and **`DELETE /media/{id}/tags/{tag_id}`** (🔓 — anyone may tag, see §7),
  `POST /media/{id}/danbooru-tags` (🔒).
- **Tags (`/tags`):** `GET /tags`, `GET /tags/display`, `GET /tags/{id}` (🔓);
  `POST /tags` (→201), `PUT /tags/{id}`, `DELETE /tags/{id}`,
  `DELETE /tags/{id}/migrate-to/{target_id}` (migrate then delete), `POST /tags/{id}/migrate` (🔒).
- **Tag categories (`/tag-categories`):** `GET` (🔓); `POST` (→201), `PUT/DELETE /{id}` (🔒).
- **Tag implications (`/tag-implications`):** `GET` (🔓); `POST` (→201),
  `DELETE /{tag_id}/{implied_tag_id}` (🔒).
- **Danbooru (`/danbooru`):** `GET` mappings (🔓); `POST/PUT/DELETE` on
  `category-mappings[/{danbooru_category_id}]` and `tag-mappings[/{id}]` (🔒).
- **Duplicates (`/duplicates`):** `GET /report`, `POST /scan`, `POST /dismissals` (🔒).

### Response conventions

- Reads return the resource/collection; creates `201` + resource; updates `200` + resource;
  deletes `204`. Controllers use `$this->success()`/`created()`/`noContent()`.
- Error: `{ "error": "PascalCaseCode", "message": "human readable" }` + status, via
  `$this->error($response, 'CodeName', 4xx, 'message')`.
- Cached GETs add `X-Cache: HIT|MISS`. Rate-limit info in `X-RateLimit-Remaining` / `Retry-After`.
- Every action/schema is annotated with `#[OAS\*]` (swagger-php spec attributes, `use OpenApi\Spec as OAS`); **regenerate `openapi.json` (`composer docs`) and
  the frontend types (`npm run gen:types`) after any contract change** — CI fails if they drift.

---

## 6. Middleware stack (order matters — defined in `api/index.php`)

Outermost → innermost:
1. **Rate limiter** (global): 120 req / 60 s per IP, SQLite-backed sliding window.
2. **CSRF origin check** (global, for POST/PUT/PATCH/DELETE): validates `Origin` against the
   allowlist; falls back to deriving the origin from `Referer`; **rejects when both are absent**
   (blocks non-browser clients like curl from bypassing CORS).
3. **CORS headers** (global) + adds `X-Frame-Options`, `X-Content-Type-Options`.
4. **OPTIONS** catch-all → 204.
5. **`$authMiddleware`** (per-group): requires a valid bearer token for state-changing methods,
   except a public allowlist matched by exact `(method, path pattern)`: `POST /media/by-ids`,
   `PATCH /media/{id}/tags`, `DELETE /media/{id}/tags/{tag_id}`. Attached to **`/media`, `/tags`,
   `/tag-categories`, `/tag-implications`, `/danbooru`, `/duplicates`** (`/auth/login` and the System
   GETs are their own unguarded routes).

Auth = opaque random token (`bin2hex(random_bytes(32))`) stored in `auth_tokens`, valid 24h,
sent as `Authorization: Bearer …`. Frontend stores it in `sessionStorage` (`useApi.ts`).

---

## 7. Security model (and the one intentional exception)

Current, hardened behavior — keep these invariants when editing auth/routes:

- **All state-changing routes require a bearer token**, enforced by `$authMiddleware` on the
  `/media`, `/tags`, `/tag-categories`, `/tag-implications`, `/danbooru`, `/duplicates` groups
  (it only gates POST/PUT/PATCH/DELETE). If you add a new write route, put it in one of those groups.
- **Public allowlist** (state-changing methods that intentionally skip auth), matched by exact
  `(method, path pattern)`: `PATCH /media/{id}/tags`, `DELETE /media/{id}/tags/{tag_id}` (anyone may
  tag media — *intentional* design choice), and `POST /media/by-ids` (a read that uses POST only to
  carry a large id list). The exact match is deliberate so a public sub-route can't widen a protected
  one (e.g. `DELETE /media/{id}` stays 🔒). Don't add to this list lightly.
- **CSRF**: state-changing requests are rejected unless `Origin` (or, failing that, `Referer`) matches
  `GALLERY_ALLOWED_ORIGINS`. Requests with neither header are rejected.
- **Login**: refused entirely if `GALLERY_ADMIN_PASSWORD` is unset (so the `'changeme'` default can't
  grant access); throttled to 10 attempts / 5 min per IP (own bucket) on top of the global limiter;
  password compared with `hash_equals()`.
- Tokens: 256-bit random, stored in `auth_tokens`, valid 24h, sent as `Authorization: Bearer …`.

Remaining low-risk note: the bearer-token lookup is a direct DB equality match (not constant-time),
which is fine given the token's entropy.

---

## 8. Frontend conventions

- **Vue 3 `<script setup lang="ts">` + Composition API only.** No Options API, no class components.
- **State**: Pinia stores (`stores/`). `gallery` holds `allTags`, `categories`, `totalMedia`,
  `blurThumbnails` (persisted to `localStorage`), and `lastViewedItemIds` (used for prev/next
  navigation on the media detail page). `favorites` is a `Set<number>` persisted to `localStorage`.
  `toast` drives `ToastContainer`.
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
  (`status`, `code`, `message`), clears the token on 401, and resolves `204`/empty bodies to
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
  files, exports and dependencies; `@vue/test-utils` is kept deliberately for component specs.
- **Data-fetching composables**: `useGalleryData` (paginated lists), `useMediaTags` (detail + tag
  add/remove). Reuse them rather than re-implementing fetch logic.
- **Routing**: lazy-loaded views in `router/index.ts`; page title from `meta.title`.
  **Infinite scroll is a store preference** (`useGalleryStore().infiniteScroll`, persisted to
  `localStorage`, toggled from the navbar's view-mode select), *not* a URL param — `perPage=0` was
  the old sentinel and any `perPage < 1` is now coerced to 40. While scrolling, `GalleryView`
  mirrors your position into the URL via `router.replace`, and the reload watcher deliberately
  ignores page-only changes so that sync doesn't re-fetch.
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
  `TagCategoryController::VALID_COLORS` (backend).
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
  media list APIs, network-only for full-size media. Registered **in production only**
  (`import.meta.env.PROD` in `main.ts`) — a worker caching `/assets/*` in front of the Vite dev
  server makes local debugging depend on which copy it hands back. The app posts
  `PREFETCH_THUMBNAILS` to warm the *next* page (paged) or the *next batch* (infinite scroll) via
  `usePrefetch.ts`; keep both call sites in `GalleryView` in sync. Bump `CACHE_VERSION` in `sw.js`
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

- **Server response cache** (`Core/ResponseCache`, file-based in `cache/api/`): GET endpoints wrap
  their generator in `cachedSuccess($response, $group, $key, $ttl, fn)`. TTLs: `TTL_SHORT=30s`
  (lists), `TTL_MEDIUM=60s` (totals/all-tags/implications).
- **Invalidation is group-based.** After any mutation, call `$this->invalidateCache('media','tags')`
  for the affected groups. **If you add a new cached GET, make sure every mutation that affects it
  invalidates the right group**, or you'll serve stale data for up to the TTL.
- SQLite connection sets WAL, `foreign_keys=ON`, `synchronous=NORMAL`, 20 MB cache, mmap, 5 s busy
  timeout (`DatabaseConnection`).
- Duplicate scan is the heaviest operation: LSH banding → Hamming filter → a comparison of each
  remaining pair's `@2x` thumbnails (not originals, to bound memory); it temporarily raises
  `memory_limit`/`max_execution_time`. **Variants are the hard part.** This gallery holds many
  versions of one artwork that differ only in a small area (an expression, a detail), and those hash
  as close as distance 0 and score whole-image SSIM up to 0.97 — so neither a tighter cutoff nor an
  SSIM floor can tell them from a re-saved copy (an SSIM floor was tried and dropped, 2026-11).
  `Core/RegionDifference` can: both thumbnails are resampled to 256², blurred to wash out compression
  noise, the overall colour offset removed, and the result is the mean difference of the *worst*
  8×8 region on any channel. Calibrated on the droplet against the 26 pairs dismissed in the UI and
  synthetic re-saves: re-saves scored ≤ 32.7, variants ≥ 40.9; coarser regions overlapped, so keep
  them fine. **Tuning:** `DUPLICATE_MAX_DISTANCE` (default 6; re-saves landed 0–5 bits apart) is the
  Hamming cutoff and `DUPLICATE_MAX_REGION_DIFF` (default 36, `off` to disable) the region ceiling;
  a pair whose thumbnails can't be read is kept. The LSH band width is derived from the cutoff
  (`bandBitsFor()`: cutoff + 1 bands, so no pair within it can miss them all). Buckets of over 100
  images are still skipped and counted as `skipped_buckets`; each can hide pairs, which is what caps
  the cutoff at 16. `dupes.php --tune` is the calibration run: it keeps dismissed pairs (flagged),
  prints a distance × region-difference table, and writes `dupes/tuning-<timestamp>.json` so the
  report the Duplicates page reads (`dupes-*.json`) is untouched. Report matches are
  `[id1, id2, distance, ssim, region_diff]` (+ `dismissed` on a tuning run); the controller reads
  the first four, so older reports still load.

---

## 10. Known traps & drift risks (read before editing)

- **Schema source of truth = Phinx** (`db/migrations/`). `db/setup.php` is now just a bootstrap that
  ensures `db/gallery.db` exists and runs migrations — no hand-written DDL. Change the schema by
  **adding a migration**, never by editing `setup.php`.
- **Upload size limits disagree**: `.htaccess` sets `upload_max_filesize 100M` / `post_max_size 105M`,
  but `UploadController::MAX_FILE_SIZE` is **500 MB** and the UI advertises 500 MB. Files >100 MB are
  rejected by PHP before reaching the controller. Reconcile if you touch uploads. *(Still open.)*
- **`ffmpeg` must be on PATH** for thumbnail generation; **GD** (with WebP, ideally AVIF) is required
  for the duplicate scan's thumbnail comparison. On hosts where `exec()` is disabled, thumbnails silently won't generate. (Note:
  `php-ffmpeg/php-ffmpeg` was removed from `composer.json` — thumbnails shell out to `ffmpeg` directly.)
- **CI runs lint, static analysis, tests, and build.** GitHub Actions (`.github/workflows/ci.yml`)
  runs, on the backend, `composer audit` + `composer normalize --dry-run` + `composer deps:check`
  (composer-unused, composer-require-checker) + `composer lint` (PHP CS Fixer / PER-CS 3.0) +
  `composer rector:check` + `composer analyse` (PHPStan level 8 + strict-rules) + `composer test`
  (PHPUnit 13). On the frontend: `npm audit --omit=dev` + `npm run lint` (ESLint, type-checked,
  incl. accessibility) + `npm run lint:css` (Stylelint) + `npm run knip` + `npm run format:check`
  (Prettier) + `npm run build` (vue-tsc + Vite) + `npm run test` (Vitest), with coverage reported
  (no hard gate). A third job runs PSScriptAnalyzer over `scripts/*.ps1` with
  `PSScriptAnalyzerSettings.psd1`. The frontend CI job checks out submodules (see below). It also
  **fails if the committed contract is stale**:
  `composer docs` + `git diff --exit-code openapi.json` (backend) and `npm run gen:types` +
  `git diff --exit-code src/types/api.generated.ts` (frontend). Backend tests build a fresh in-memory SQLite DB from
  `backend/tests/Support/schema.sql` and pass it to Storage/Collection constructors (the app
  uses **constructor DI** — the PHP-DI container in `api/dependencies.php` supplies `PDO` and
  autowires the graph). If you change a migration, regenerate `schema.sql` (see CONTRIBUTING.md).
  Frontend tests are in `src/__tests__/*.spec.ts` (happy-dom). Untested by design: HTTP
  controllers, and the `ffmpeg`/curl/image-pipeline helpers.
- **Deploys snapshot the database before migrating** — `scripts/deploy.ps1` writes
  `db/.backups/gallery-<timestamp>.db` via SQLite `VACUUM INTO` (keeping five) and aborts before
  migrating if the snapshot fails. Use `VACUUM INTO`, never `cp`: the connection runs in WAL mode,
  so a plain copy misses whatever is still in the `-wal` sidecar. The snapshot is deliberately
  **not** auto-restored by the failure trap — that trap fires on any non-zero exit, including
  after a migration has already succeeded, and reverting data could discard media uploaded during
  the deploy window; a failed migration prints the path and the restore command instead.
- **OpenAPI is swagger-php 7's spec attributes** (`use OpenApi\Spec as OAS;`), migrated from the
  classic `OpenApi\Attributes` with the package's own Rector set
  (`vendor/zircote/swagger-php/rector/set/classic-to-spec.php`). The document is built by
  `tests/Support/OpenApiDocument::builder()`, which both `composer docs` (`bin/openapi.php`) and
  `OpenApiSpecTest` use, so the two can't disagree about how it's built. That builder drops v7's
  `Properties` augmenter, which would publish every PHP property initialiser on a doc-only response
  class as a schema `default` (81 of them, e.g. `success: true` on `ScanResult`); put a real default
  on the attribute instead. swagger-php 8 moves the spec classes from `OpenApi\Spec` to
  `OpenApi\Attributes`: one `use` line per file, and the same Rector set handles it.
- **Fingerprints are only comparable with ones computed the same way.** Anything that changes how
  `MediaCollection::createFingerprint()` hashes (algorithm, library, image driver) needs
  `php backend/scripts/regenerate-fingerprints.php` on the droplet right after the deploy, before
  the next scan or ingest: until it finishes, old and new fingerprints sit side by side and their
  distances mean nothing. The 2026-11 move from `jenssegers/imagehash` to `intervention/imagehash`
  was one: same pHash, but the 8×8 block is read transposed, so every bit moved. Hashing is pinned
  to GD for the same reason — the old library used Imagick when it was loaded, so the hash depended
  on the machine. The script clears (rather than keeps) a fingerprint it can't recompute, so a
  failure drops that image from the scan instead of leaving a stale-format hash in it.
- **`config.platform.php` in `backend/composer.json` mirrors the droplet's PHP** (8.5.12 as of
  2026-11), so Composer resolves for what production runs, not your machine. Raise it only after
  upgrading the droplet. `roave/security-advisories` conflicts with PHP releases that have known
  advisories, so when the pin falls behind, `composer update` refuses to resolve anything; that
  happened at 8.5.6 and is the signal to upgrade PHP on the droplet, not to raise the pin alone.
- **Line endings are LF, enforced by `.gitattributes` (`* text=auto eol=lf`).** The PHP coding
  standard fails a file on CRLF (phpcs did, and PHP CS Fixer does) and Prettier rewrites it, so a Windows checkout without this produced 34 lint
  errors in files nobody had touched. `eol=lf` overrides `core.autocrlf`, so no per-developer git
  config is required — do not "fix" a CRLF complaint by changing your local `core.autocrlf`. Note
  that converting a file's line endings does *not* fix line endings already escaped inside a
  generated artifact: `openapi.json` carried `\r\n` inside description strings taken from PHP
  docblocks, and needed `composer docs` to clear (the `OpenApiSpecTest` catches this).
- **Do not upgrade to TypeScript 7 yet.** TS 7 is the native port and removed the `./lib/tsc`
  subpath from its package `exports`. `vue-tsc` resolves that path directly and exits with
  `ERR_PACKAGE_PATH_NOT_EXPORTED`, so the type-check (`npm run build`) cannot run at all — and
  `vue-tsc`'s `typescript: ">=5.0.0"` peer range does not express the incompatibility, so npm will
  install the combination without complaint. Still true of 3.3.14 (2026-11): its only addition is
  following `typescript` when it is aliased to `@typescript/typescript6`. Stay on `~6.0.3` until
  vue-tsc ships TS 7 support.

- **Version bumps are the release trigger.** CI tags and publishes `v<version>` from
  `frontend/package.json` using the matching `## [<version>]` section of `CHANGELOG.md` as the
  release body, so bump the version and write its section in the same commit. The **HTTP contract
  version is separate** — `Configuration::API_VERSION` — and only moves when the API surface
  changes.
- Release history lives in `CHANGELOG.md` (Keep a Changelog format). A historical
  `IMPROVEMENTS.md` also exists (gitignored) — old internal review notes, not a current audit;
  don't trust it as the state of the codebase.

---

## 11. How to add things (quick recipes)

**A new API endpoint**
1. Add SQL to the relevant `*Repository`/`*Storage` class (prepared statements only).
2. Expose it via the matching repository/collection method.
3. Add a controller action; validate input, return via `success()`/`created()`/`noContent()`/`error()`
   with the right status code, and **annotate it with `#[OAS\Operation\Get/Post/…]`** (+ any new response schema
   under `Structure/Api/`).
4. Register the route in `api/index.php` in the correct group (mind auth + caching); public writes
   go on the `(method, path)` allowlist in `$authMiddleware`.
5. If it's a cached GET, use `cachedSuccess`; if it's a mutation, call `invalidateCache`.
6. Run `composer docs` (backend) and `npm run gen:types` (frontend); commit both regenerated files.

**A new media field**
Update: a **migration**, the `Media` structure (+setter + `#[OAS\Property]`),
`MediaStorage` (`store`/`retrieve*` column lists), then regenerate the spec + types
(`composer docs`, `npm run gen:types`) so the frontend `Media` type picks it up.

**A new tag-category color**
Add to `TagCategoryController::VALID_COLORS` (backend) **and** `VALID_COLORS` in
`constants/categories.ts` (frontend) **and** define the CSS in `frontend/src/style.css`.

**A new frontend page**
Create a view in `views/`, add a lazy route with `meta.title` in `router/index.ts`, fetch via
`useApi`/a composable, and link it from `AppNavbar.vue`.

---

## 12. Conventions checklist for any change

- [ ] SQL only in `Repository`/`Storage`; bound parameters always.
- [ ] Controllers return the right status code (`200`/`201`/`204`) via `success()`/`created()`/
      `noContent()`, or `error()` with a PascalCase code + human message.
- [ ] Endpoints/schemas are `#[OAS\*]`-annotated; `composer docs` + `npm run gen:types` re-run and
      the regenerated `openapi.json` / `api.generated.ts` committed (CI enforces).
- [ ] Mutations invalidate the right cache group(s).
- [ ] New state-changing routes are in an auth-protected group (or added to the public allowlist consciously).
- [ ] Schema changes land in a **Phinx migration** (never hand-edited into `db/setup.php`).
- [ ] Frontend: typed (import from `types/`), `<script setup>`, API via `useApi` + `api/endpoints.ts`, no direct `fetch`.
- [ ] Bump `sw.js` `CACHE_VERSION` if caching/asset behavior changed.
- [ ] Keep frontend/backend mirror lists (colors, extensions) in sync.
```
