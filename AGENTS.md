# AGENTS.md

Operational guide for AI coding agents (and humans) working in this repository.
Read this before making changes. It describes the architecture, conventions,
data model, commands, and the non-obvious gotchas that will bite you.

The rules every project follows are in [shared/standards/README.md](shared/standards/README.md), the shared-tooling guide. This file holds only what is specific to this project; where it says nothing, the shared rules win. Where this project departs from them, and why, is [§13](#13-departures-from-the-standards); what it doesn't meet yet is [§14](#14-not-yet-meeting-the-standards).

> **The backend is Laravel** (since 5.0.0, live from 2026-11-03). It replaced a Slim 4 app
> without changing the API contract. How that was done, and every deliberate difference, is in
> [docs/laravel-migration.md](docs/laravel-migration.md). The contract recorded from the Slim app
> is frozen in `backend/tests/Fixtures/contract/` and replayed by the tests, translated into
> API 8's terms (§10).

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
- **Accounts** (since 6.0.0): open sign-up and login by username and password, behind Cloudflare
  Turnstile, or by **passkey** (since 7.0.0). A browser signs in with an HttpOnly session cookie.
  An account keeps **favorite lists** (the heart is its default "Favorites" list), **blocked
  tags** (media carrying one leaves its listings) and **saved searches**. Signed out, favorites
  stay in the browser's `localStorage`, and move into the account at the first sign-in.
- An **admin** is an account with `is_admin`: **uploads**, **deletes**, tag management, the
  duplicate scan and the Users page are theirs. The first admin is made with `gallery:user`.
- A nightly **ingest** of files dropped into the media folder, and queued **background work**:
  ingest, Danbooru imports, and rebuilding thumbnails, fingerprints or metadata.
- A PWA-ish **service worker** for thumbnail caching and adjacent-page prefetch.
- Light, dark or the device's **colour scheme**, chosen in the navbar and remembered in the browser.

### Tech stack

| Layer | Tech |
| --- | --- |
| Backend | PHP **8.5**, **Laravel 13**: Eloquent, Form Requests, API Resources, the session guard, the database queue, the scheduler; `laravel/passkeys` (WebAuthn) |
| Database | **SQLite** (one file, WAL mode); Laravel migrations in `backend/database/migrations/` |
| Image/video tooling | `ffmpeg`/`ffprobe` through Laravel's `Process`, PHP GD, `intervention/imagehash` (perceptual hash, always on GD) |
| HTTP client | Laravel's `Http` for Danbooru, faked in tests |
| API docs | swagger-php 7 attributes → committed `backend/openapi.json`, served at `/api/openapi.json` and drawn at `/api/docs` by Swagger UI (`swagger-ui-dist`, from the build) |
| Frontend | **Vue 3** (`<script setup>` + Composition API), TypeScript, [Pinia](https://pinia.vuejs.org/), Vue Router, `@simplewebauthn/browser` (passkeys) |
| Build | **Vite** on Node **26+** (outputs to `frontend/dist`) |
| Styling | **Bulma 1.x** components and layout helpers, the palette in `tokens.css` (light and dark through `light-dark()`) and the rest in `style.css`, Font Awesome Pro from the kit package `@awesome.me/kit-4f2bd9b614` through `<font-awesome-icon>` |
| Tests | **Pest 5** (`backend/tests/`, in-memory SQLite) + **Vitest** (`frontend/src/__tests__/`, jsdom) + **Playwright** with axe (`frontend/e2e/`, the built SPA served through Laravel, under its real Content-Security-Policy) |
| Shared tooling | **[shared-tooling](https://github.com/jwill89/shared-tooling)**, the submodule at `shared/`: lint, test, CI and deploy settings every project uses |

---

## 2. Repository layout

```text
/
├── backend/                     # The Laravel app; its README covers local setup and the checks
│   ├── app/
│   │   ├── Http/
│   │   │   ├── Controllers/Api/       # One controller per resource; every action #[OAS\*]-annotated
│   │   │   ├── Controllers/SpaController.php  # The SPA shell: index.html + each page's link-preview tags
│   │   │   ├── Middleware/            # SecurityHeaders (CSP, nosniff, Referrer-Policy), VerifyOrigin (the CSRF origin
│   │   │   │                          #   check), RejectMalformedJson, EnsureAdmin
│   │   │   ├── Requests/              # ApiRequest (422 problem details) + one per write; MediaListRequest,
│   │   │   │                          #   TagListRequest, PageRequest for the listings' query parameters
│   │   │   └── Resources/             # The JSON shapes: Media, Tag, TagCategory, mappings, …
│   │   ├── Models/                    # Eloquent: Media, Tag, TagCategory, DismissedDuplicate,
│   │   │                              #   DanbooruCategoryMapping, DanbooruTagMapping,
│   │   │                              #   User (an account), Passkey (laravel/passkeys', with the stored time format),
│   │   │                              #   FavoriteList, SavedSearch
│   │   ├── Services/
│   │   │   ├── Danbooru/              # DanbooruTagger (MD5 → IQDB, import rules), DanbooruFailure
│   │   │   ├── Duplicates/            # DuplicateScanner (LSH → Hamming → RegionDifference), ScanSettings
│   │   │   ├── Media/                 # MediaIngest, MediaMetadata, Thumbnailer (ffmpeg)
│   │   │   └── Fingerprinter.php      # The perceptual hash (pHash on GD)
│   │   ├── Jobs/                      # ProcessNewMedia, ImportDanbooruTags, RebuildFromFile
│   │   ├── Console/Commands/          # gallery:ingest, gallery:import-tags, gallery:regenerate, gallery:dupes
│   │   ├── Support/                   # ResponseCache + CacheGroup (cached reads), Login (signing a session in and out),
│   │   │                              #   HashedSessionHandler (sessions stored under the hash of their id),
│   │   │                              #   ClientAddress (what a limit counts a visitor by), Viewer (who reads a
│   │   │                              #   public listing), ContentSecurityPolicy (the policies and the nonce),
│   │   │                              #   Timestamp (the RFC 3339 form every stored time takes)
│   │   ├── Exceptions/                # ApiException + ApiExceptionRenderer: RFC 9457 problem details for all of /api
│   │   ├── OpenApi/                   # Global OAS metadata (holds API_VERSION); Bodies/ (request bodies) and
│   │   │                              #   Schemas/ (response shapes, Problem), all doc-only
│   │   └── Providers/AppServiceProvider.php  # rate limiters, CORS origins, the media link, unwrapped resources
│   ├── bootstrap/app.php        # Middleware stack and exception rendering
│   ├── config/gallery.php       # Every gallery setting, read from .env (code never calls env())
│   ├── config/hashing.php       # Passwords: argon2id at 64 MiB, one pass, four lanes
│   ├── config/session.php       # Sessions: the database driver, the __Host- cookie name
│   ├── database/migrations/     # The schema; the baseline is the Slim app's, created only where missing
│   ├── routes/
│   │   ├── api.php              # Every /api route: public, then any account's, then the admin's
│   │   ├── web.php              # The SPA shell's fallback route: everything outside /api
│   │   └── console.php          # The schedule
│   ├── public/                  # Apache's DocumentRoot: index.php, .htaccess, .user.ini (upload limits), the built SPA
│   │                            #   (the deploy copies frontend/dist here), media → the media folder
│   ├── resources/spa/index.html # The built SPA's index.html, which the shell serves (deploy copies it; gitignored)
│   ├── storage/                 # Logs and the file cache (responses, rate limits, locks); contents gitignored
│   ├── tests/
│   │   ├── Feature/             # HTTP, console, jobs, models, services; Contract/ replays the recordings
│   │   ├── Unit/                # The OpenAPI document, the fingerprinter, the region difference
│   │   ├── Support/             # The contract replay (Contract/), fixture seeder, OpenAPI builder, test images
│   │   └── Fixtures/            # Frozen contract recordings (contract/), contract.sql, schema.sql (what the
│   │                            #   migrations build), slim-schema.sql (the live database before them), files
│   ├── bin/openapi.php          # `composer docs`: writes openapi.json
│   ├── bin/schema.php           # writes tests/Fixtures/schema.sql from a freshly migrated database
│   ├── openapi.json             # Generated, committed; CI fails if it is stale
│   ├── .php-cs-fixer.dist.php · phpstan.neon · rector.php · phpunit.xml  # each starts from shared/lint/php
│   └── .env.example             # Every setting, documented
│
├── frontend/                  # Vue 3 + Vite source
│   ├── vite.config.ts         # Vite + Vitest config (build outDir, dev proxy, test block, the service worker's build)
│   ├── playwright.config.ts   # The end-to-end run: the built SPA through Laravel on PHP's server, a throwaway database
│   ├── e2e/                   # Its specs (*.spec.ts), settings (env.ts) and database prepare script (server/)
│   ├── tsconfig.{app,sw,node,vitest}.json  # the app (DOM), the service worker (WebWorker), the Vite config, the specs
│   ├── vitest.setup.ts        # Vitest setup (pins TZ=UTC)
│   ├── eslint.config.ts       # shared config (@jwill89/lint-web, from shared/lint/web) + this project's rules
│   ├── stylelint.config.js    # the shared CSS rules + the palette rule, off for src/tokens.css alone
│   ├── .npmrc · .nvmrc        # install-links (local packages copied, not linked) · Node 26
│   ├── knip.ts                # unused files / exports / dependencies
│   ├── src/
│   │   ├── main.ts            # App bootstrap (theme first), global error handler, SW registration
│   │   ├── theme.ts           # The colour scheme: data-theme on <html>, the reader's pick remembered
│   │   ├── storage.ts         # readPreference / writePreference: localStorage that never throws
│   │   ├── csp.ts             # The page's Content-Security-Policy nonce (Turnstile's script, Reka's styles)
│   │   ├── router/index.ts    # Routes (lazy-loaded views)
│   │   ├── stores/            # Pinia: gallery (tags/categories/totals/blur), auth, account
│   │   │                      #   (blocked tags, saved searches), favorites (+ lists), toast
│   │   ├── composables/       # useApi (fetch wrapper, problem details), useGalleryData, useMediaTags, usePrefetch,
│   │   │                      #   useTurnstile (loads Cloudflare's script)
│   │   ├── components/        # Reusable UI (GalleryCard, TagBadge, TagMultiSelect, ThemeMenu, modals…)
│   │   ├── views/             # Route-level pages, PrivacyView and AccessibilityView among them
│   │   ├── constants/         # categories.ts (color → CSS class helpers), uploads.ts (upload batching), paging.ts,
│   │   │                      #   thumbnails.ts (thumbnail URLs + the prefetch message, shared with the worker)
│   │   ├── sw/                # The service worker: sw.ts (events) + cache.ts (caching, prefetch); built to /sw.js
│   │   ├── __tests__/         # Vitest specs (*.spec.ts): useApi, endpoints, stores, theme, storage, toast, …
│   │   ├── tokens.css         # The palette: every colour token, light and dark
│   │   └── style.css          # Custom styles and Bulma overrides, drawn from the tokens
│   └── dist/                  # Vite build output (gitignored); the deploy copies it into backend/public
│
├── shared/                    # The shared-tooling submodule, pinned to a release tag (`task shared:update -- vX.Y.Z`)
├── .github/workflows/ci.yml   # CI: the shared php, web, e2e, pwsh, docs and osv workflows, then this repo's release
├── .github/dependabot.yml     # Weekly Composer, npm and Actions updates, minors and patches grouped
├── Taskfile.yml               # `task lint`, `task test`, `task check`, `task deploy`; the shared:* tasks
├── .editorconfig · .gitattributes  # From shared/repo (Sync-RepoFiles): LF, UTF-8, 2 spaces (4 for PHP,
│                              #   PowerShell, the backend's JSON); this repo's lines sit below the marker
├── .markdownlint-cli2.jsonc   # markdownlint: the shared config, plus what it skips
├── README.md · CLAUDE.md · CONTRIBUTING.md · CHANGELOG.md · LICENSE
├── deploy/                    # The server files every deploy installs where they differ: the Apache vhost (one file),
│                              #   the queue worker's unit and the scheduler's cron file, with __PLACEHOLDERS__ for host values
├── docs/ops.md                # The live site's runbook: host setup, backups and restores, checks, upgrades, rollback, logs
├── docs/decisions.md          # Dated decisions, the accessibility exceptions among them
├── docs/laravel-migration.md  # How the backend moved from Slim to Laravel: phases, contract changes, deployment
├── scripts/                   # Dev-only: deploy.ps1 (shared SharedDeploy module, PuTTY plink → droplet) + config template
└── devdata/                   # Git-ignored: the dev machine's copy of the live media folder, which each deploy updates
```

### Layering convention (backend)

`Route → middleware → Form Request → Controller → Model / Service → Eloquent (SQLite)`

- **Models** are Eloquent, with the domain rules that belong to one table: `Media::typeOf()`
  and `isAnimatedGif()`, `Media::thumbnailPaths()`, the tag-search scopes, `Tag::normalizeName()`,
  `Tag::withImplied()`, `Tag::moveMediaTo()`. Deleting a `Media` removes its files, and saving or
  deleting a model clears the cached reads it affects (§9).
- **Form Requests** validate a write's body or a listing's query. They extend `ApiRequest`, which
  fails with a 422 problem details body named by the first broken rule's type (the endpoint's own,
  from `errorCodes()`), plus `errors` listing every broken field with its location.
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

What to install first is in [README.md's Requirements](README.md#requirements). Fill in the `GALLERY_*` settings as `.env.example` describes, and make yourself an admin with `php artisan gallery:user <name> --admin`. The Vite dev server proxies `/api` and the media files (`/media/full`, `/media/thumbs`) to `http://localhost` (`frontend/vite.config.ts`), so serve the backend there, or set `GALLERY_BACKEND_URL` to `php artisan serve`'s address (`http://127.0.0.1:8000`) before `npm run dev`. The SPA's own pages under `/media` stay with Vite. The dev server's origin, `http://localhost:5173`, must be in `GALLERY_ALLOWED_ORIGINS` for any write.

The checks CI runs, from `backend/`:

```bash
composer lint            # PHP CS Fixer (PER Coding Style 3.0); `composer lint:fix` applies the fixes
composer analyse         # Larastan level 8 + strict and deprecation rules
composer rector:check    # current PHP and Laravel idioms, no dead code; `composer rector` applies
composer test            # Pest, including the contract replay, under a far-off zone (Pacific/Kiritimati)
composer test:coverage   # the same, then the coverage floor (94% of lines, 86% of methods)
composer docs:check      # regenerate openapi.json; fails if it differs from git's copy (so commit it first)
```

`task lint`, `task test` and `task check` (everything CI runs) do the same from the repo root.

### Database

**Laravel migrations (`backend/database/migrations/`) are the schema.** The baseline, `0001_01_01_000000_create_gallery_tables`, creates each gallery table only where it is missing: on a fresh database it builds the schema, and on the live one (built by the Slim app's Phinx migrations) it does nothing. Change the schema by **adding a migration** (`php artisan make:migration <name>`).

### Frontend

```bash
cd frontend
npm install
npm run dev        # Vite dev server on :5173, proxies /api and the media files to http://localhost
npm run test:e2e   # the end-to-end suite: builds, then serves the SPA through Laravel on a throwaway database
npm run build      # vue-tsc type-check + vite build → outputs to frontend/dist
npm run preview
```

`npm install` needs `shared/` checked out: it installs `@jwill89/lint-web` from `file:../shared/lint/web`, and the backend's lint, Rector and PHPStan configs include shared-tooling's too. The checks CI runs, from `frontend/`:

```bash
npm run lint            # ESLint (type-checked, incl. template accessibility); `npm run lint:fix` fixes what it can
npm run lint:css        # Stylelint: the shared rules + the palette rule; `npm run lint:css:fix`
npm run knip            # no unused files, exports or dependencies
npm run format:check    # Prettier; `npm run format` formats the frontend
npm run gen:check       # regenerate src/types/api.generated.ts; fails if it differs from git's copy
npm run build           # vue-tsc type-check + vite build
npm run test            # Vitest once; `npm run test:watch` watches
npm run test:coverage   # Vitest, failing below the floor in vite.config.ts
```

The production app is **the built `dist/`**, copied into `backend/public/` (its `index.html` into
`resources/spa/`) and served by Apache and the SPA shell; there is no Node server in prod.

### Background work: commands, queue and schedule

Artisan commands, run from the app folder (on the droplet as `www-data`:
`sudo -u www-data php artisan …`):

```bash
php artisan gallery:user <name> --admin      # make an account (asks for its password), or make one an admin;
                                             #   --no-admin, --password (a new one, signing it out everywhere)
php artisan gallery:ingest [--import-tags]   # queue the files dropped into the media folder; remove items whose files are gone
php artisan gallery:import-tags [id]         # queue a Danbooru import for every item, or one
php artisan gallery:regenerate thumbnails    # or fingerprints, or metadata: queue a rebuild of every item from its file
php artisan gallery:dupes                    # scan for duplicates → dupes/dupes-YYYY-MM-DD.json
php artisan gallery:dupes --tune --max-distance=8 --max-region-diff=off  # calibrate (see §9)
```

- **The queue** is the `database` driver. On the droplet the systemd unit `gallery-queue`
  (`deploy/gallery-queue.service`) runs `php artisan queue:work --tries=3 --max-time=3600` as
  its own account in www-data's group (§3, Deploy) and restarts by itself (`Restart=always`: the
  worker leaves with status 0 after an hour, which `on-failure` would not start again); every
  deploy stops it before the migration and starts it on the new code. Jobs: `ProcessNewMedia` (one ingested file, unique per name), `ImportDanbooruTags`
  (rate-limited to 30 a minute, retried for a day), `RebuildFromFile`.
- **The schedule** (`routes/console.php`) runs `gallery:ingest` at midnight, in
  `GALLERY_SCHEDULE_TIMEZONE` (UTC on the droplet). `/etc/cron.d/gallery-scheduler`
  (`deploy/gallery-scheduler.cron`) runs `php artisan schedule:run` every minute as `www-data`.
  Expired sessions need no schedule: the database handler sweeps them as requests arrive
  (`session.lottery`).
- `gallery:ingest` refuses to remove anything when none of the gallery's files are in
  `media/full`, which is what a wrong `GALLERY_MEDIA_PATH` looks like.

### Deploy (to the DigitalOcean droplet)

```powershell
task deploy                     # build the SPA → pack the code → upload → composer install → migrate → optimize
task deploy -- -Force           # the release again, though the droplet runs it
task deploy -- -Preview         # try the working tree, untagged, stamped preview-<date-time>
```

It ships only a release: the newest tag CI made for `frontend/package.json`'s version (it fetches the tags first), built in a checkout of its own, so commits after it stay out; when the droplet already runs it, nothing is done. Anything else goes out only with `-Preview`.

`scripts/deploy.ps1` is built on shared-tooling's SharedDeploy module (`shared/deploy/pwsh`; PuTTY plink). Its settings come from the untracked `scripts/deploy.config.ps1`, whose example names them: the SSH host, user and key, `WebRoot` (the app's folder), `CertName`, `ServerAdmin` and `ServiceUser` (the queue worker's account), with `ServerName` and `SiteUrl` defaulting to `gallery.mathdad.me` and `https://gallery.mathdad.me`. It ships **code, the release's `package.json`, and the server files in `deploy/`**, filled with those settings by `Expand-DeployTemplate`, as one archive whose SHA-256 and file count the host checks before anything changes. It never ships `.env`, `storage/`, `vendor/`, the data or the backups. On the host it:

1. checks first, changing nothing if one fails: `.env` is there, the worker's account exists with www-data as its primary group, the database and the media folder sit where the worker's unit lets it write, and the folders and the database it shares with PHP-FPM are group www-data and group-writable;
2. builds the new code whole in `.deploy-next/`: unpacked, `vendor/` copied from the live code, `composer install --no-dev --no-scripts`, the `public/media` link copied, all of it owned by `www-data`;
3. snapshots the database (shared-tooling's `Backup-RemoteDatabase`, into `db/.backups/`, keeping five) and installs the vhost (`Switch-RemoteItemSet -Vhost -ServerName`); a vhost Apache refuses, on a copy of its config or at `configtest`, stops the deploy here, with the live code untouched;
4. swaps the code in by renaming each top-level entry, moving the previous code to `.deploy-backup/`, which goes back in place automatically if a later step fails;
5. stops the worker, then runs as `www-data` `php artisan migrate --force` (first, since the new code needs its tables before it can serve a request), `package:discover`, `storage:link` where the link is missing, and `optimize --except=views`;
6. installs the worker's unit and the scheduler's cron file where they differ (`Install-HostConfig`, keeping the previous copies in `/var/lib/shared-deploy/gallery`), restarts the worker on the new code and requires it to stay up; if it doesn't, the files and the code go back and the worker restarts on the previous code. Apache reloads if the vhost changed.

Then, from your machine through Cloudflare, it checks `/api/media/count` and that `/` names this build's entry script (`Invoke-SmokeCheck -EntryScript`). If either fails, the previous code, vhost, unit and cron file go back (`Restore-RemoteItemSet`, `Restore-HostConfig`; the database stays as it is), unless `-NoBackup` left nothing to go back to. Once they pass, it keeps the daily database backup installed (`Install-BackupTimer`), pulls a copy of the live database to the local one `backend/.env` names, and brings the dev machine's copy of the media folder up to date in `devdata\media` (`Sync-LiveFolder`, the thumbnails left out); `-NoDbPull` skips both. What each backup holds, where it is and how it comes back is in [docs/ops.md](docs/ops.md).

**The server files** are `deploy/apache-vhost.conf`, `gallery-queue.service` and `gallery-scheduler.cron` (`/etc/cron.d/gallery-scheduler`). Their host values are placeholders (`__WEB_ROOT__`, `__SERVICE_USER__`, `__CERT_NAME__`, …), so nothing about the host's layout is in the repo. The vhost is one file for the site, `sites-available/<ServerName>.conf`, holding the port-80 redirect and the https site, with HSTS and the visitor's address taken from Cloudflare by `mod_remoteip`. SharedDeploy disables every other enabled site serving the name, such as certbot's `-le-ssl` file, tries the change on a copy of Apache's config, and puts everything back on a rollback. Nobody edits any of them on the host: a change goes into the template and ships with the next deploy ([docs/ops.md](docs/ops.md), The server files).

**The queue worker** runs as its own locked account (`ServiceUser`), whose primary group is www-data, under `UMask=0002`, at idle CPU and I/O priority, with the shared hardening block and `ReadWritePaths` of `storage/`, `db/` and `media/`. The folders it shares with PHP-FPM are www-data's, setgid and group-writable; the app makes its log 0664 (`config/logging.php`) and its file cache, folders and files alike, 0770 (`config/cache.php`), and SQLite gives the database's `-wal` and `-shm` the database file's own mode. docs/ops.md's Host setup has the commands.

Why the deploy builds beside the live code: until 2026-11-03 it deleted the code and unpacked the new code over the live folder, then ran Composer, the migration and `optimize`. For those 10–20 s every request failed: `bootstrap/cache` belonged to root, so Laravel couldn't boot, and its error page then died on `Target class [view] does not exist`. `public/media` was missing too, so thumbnail requests fell through to Laravel. A single deploy logged 75 fatal errors.

It holds one SSH connection for the whole run (plink `-share`), to respect the droplet's `ufw limit ssh`, and opens a second only if that one drops.

Everything for the gallery lives in its web root on the droplet, `<web root>` below (the `WebRoot` setting):

| Path | Holds |
| --- | --- |
| `app/`, `bootstrap/`, `config/`, `database/`, `resources/`, `routes/`, `vendor/`, `package.json`, … | The code, replaced by each deploy |
| `public/` | Apache's DocumentRoot, the only part served |
| `.env`, `storage/` | Settings and Laravel's runtime files, kept across deploys |
| `db/`, `media/`, `dupes/` | The data: `gallery.db` (+ `.backups/`, the deploy's snapshots, and `backups/`, the daily copies), the media and the ingest folder, scan reports |
| `backups/` | The cutover's backups, `ROLLBACK.txt` (its steps back to the Slim app, deleted 2026-11-16, no longer apply), the PHP-FPM switch's way back (`fpm-switch/`); root-only |

### Config (`backend/.env`, gitignored; documented in `backend/.env.example`)

Laravel's own keys (`APP_KEY`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, `LOG_*`, `QUEUE_CONNECTION=database`, `CACHE_STORE=file`, `DB_DATABASE`, and the session's: `SESSION_DRIVER=database`, `SESSION_LIFETIME=1440`, `SESSION_EXPIRE_ON_CLOSE=true`) plus the gallery's, which `config/gallery.php` reads, every one starting `GALLERY_`. `APP_URL`'s host is the site passkeys belong to (`config/passkeys.php`), and an https `APP_URL` makes the session cookie Secure and names it `__Host-gallery-session` (`config/session.php`: the prefix goes on only for a Secure cookie with `Path=/` and no `Domain`, the three things a browser requires of it). Password hashing (`config/hashing.php`) reads its cost from `ARGON_MEMORY`, `ARGON_TIME` and `ARGON_THREADS` only so that `phpunit.xml` can lower it; production sets none.

```text
GALLERY_ALLOW_SIGNUP               # anyone may make an account unless "false" (admins come from gallery:user)
GALLERY_TURNSTILE_SITEKEY          # Turnstile's site key, sent to the sign-in pages (GET /api/auth/config)
GALLERY_TURNSTILE_SECRET           # Turnstile's secret; empty switches the bot check off (local, tests)
GALLERY_URL                        # public base URL, for Danbooru's IQDB fallback
GALLERY_ALLOWED_ORIGINS            # comma-separated CORS + CSRF origin allowlist
GALLERY_MEDIA_PATH                 # media folder (full/, thumbs/, the ingest folder); default storage/app/media
GALLERY_DUPES_PATH                 # duplicate-scan reports; default storage/app/dupes
GALLERY_DANBOORU_LOGIN             # Danbooru API username
GALLERY_DANBOORU_API_KEY           # Danbooru API key
GALLERY_DANBOORU_USER_ID           # the account's numeric ID, sent in the User-Agent; Danbooru's firewall blocks clients without it
GALLERY_DUPLICATE_MAX_DISTANCE     # duplicate scan: largest Hamming distance reported, 0-16 (default 6)
GALLERY_DUPLICATE_MAX_REGION_DIFF  # duplicate scan: largest worst-region difference, 0-255 or off (default 36)
GALLERY_SCHEDULE_TIMEZONE          # the schedule's timezone (UTC on the droplet)
GALLERY_CSP_REPORT_ONLY            # true sends the Content-Security-Policy as report-only (docs/ops.md); off unless set
```

Real environment variables take precedence over `.env`. The gallery's values are read raw, so a password of `null` or `true` stays a string; an old name (`DANBOORU_LOGIN`, `CLOUDFLARE_TURNSTILE_SECRET`, …) is ignored. The app's version isn't a setting: `config/gallery.php` reads it from `package.json` (§10). **The production config is cached** (`optimize`), so a `.env` change takes effect only after the next deploy, or `php artisan optimize --except=views` as `www-data`.

## 4. Data model (SQLite)

```text
media(media_id PK, media_type['image'|'video'], file_name UNIQUE, file_time[time], hash[MD5], bits_fingerprint,
      width, height, duration[secs, videos], file_size[bytes])   # metadata extracted at ingest
tag_categories(category_id PK, category_name UNIQUE COLLATE NOCASE, color[tag hue], description, sort_order)
tags(tag_id PK, category_id FK→tag_categories, tag_name UNIQUE COLLATE NOCASE)
media_tags(media_id FK, tag_id FK)              # junction, PK(media_id, tag_id)
tag_implications(tag_id FK, implied_tag_id FK)  # PK(tag_id, implied_tag_id)
danbooru_category_map(danbooru_category_id PK, danbooru_category_name, gallery_category_id FK)
danbooru_tag_map(id PK, danbooru_tag UNIQUE, gallery_tag)
dismissed_duplicates(media_id_1, media_id_2, dismissed_at[time])  # PK(id1,id2), stored with id1<id2

users(id, username UNIQUE NOCASE, password[argon2id], remember_token, is_admin, created_at, updated_at)  # accounts
sessions(id[SHA-256 of the session id], user_id, ip_address, user_agent, payload, last_activity)  # one per browser
favorite_lists(list_id, user_id FK, name NOCASE, is_default, created_at, updated_at)  # UNIQUE(user_id, name); one default each
favorite_list_items(list_id FK, media_id FK, added_at)            # PK(list_id, media_id)
blocked_tags(user_id FK, tag_id FK, created_at)                   # PK(user_id, tag_id)
saved_searches(search_id, user_id FK, name NOCASE, query, created_at, updated_at)  # UNIQUE(user_id, name)
passkeys(id, user_id FK, name, credential_id UNIQUE, credential[json], last_used_at, created_at, updated_at)  # laravel/passkeys
jobs · job_batches · failed_jobs                # the database queue
migrations                                      # Laravel's migration log
```

**Times** are `DATETIME` columns holding RFC 3339 in UTC, to the second (`2026-11-07T09:18:09Z`),
each with `CHECK (col IS strftime('%Y-%m-%dT%H:%M:%SZ', col))`: `media.file_time`,
`dismissed_at`, `added_at`, and the account tables' `created_at`, `updated_at` and `last_used_at`.
The models write them in that form (`$dateFormat`, `App\Support\Timestamp`), and the API sends
them as stored. Migration `2026_11_15_000001` moved the live rows from Unix seconds and Laravel's
`Y-m-d H:i:s` by rebuilding each table; the framework's own tables keep the times the framework
writes (§13).

The Slim app's `auth_tokens`, `rate_limits` and `phinx_migrations` are dropped by migration
`2026_11_15_000000`, where the live database still had them. Rate limits live in the cache, not a
table. Sanctum's `personal_access_tokens` went with the bearer tokens in 7.0.0.

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

## 5. API surface (REST, all under `/api`)

The **authoritative contract** is the generated OpenAPI 3.1 spec (`backend/openapi.json`, `composer docs`), served at **`/api/openapi.json`** and drawn by **Swagger UI** at **`/api/docs`** (its files come from `swagger-ui-dist`, copied into the build's `api-docs/` by `vite.config.ts`, so nothing loads from a CDN). Every operation has a readable `operationId` (`listMedia`, `uploadMedia`, …). The API's own version is `App\OpenApi\OpenApiSpec::API_VERSION`, **8.0.0**. Below is the shape; 🔓 = public, 🔑 = any signed-in account, 🔒 = an admin account.

- **System / Auth:** `GET /version`, `GET /openapi.json`, `GET /docs` (🔓); `GET /auth/config` (🔓 `{ signupOpen, turnstileSiteKey }`), `GET /auth/session` (🔓 who this browser is signed in as, or `null`), `POST /auth/register`, `POST /auth/login` (🔓, with `turnstileToken` while Turnstile is on; each answers `{ user }` and sets the session cookie), `POST /auth/passkey/options` and `POST /auth/passkey` (🔓 sign in with a passkey); `DELETE /auth/session` (🔑 log out).
- **Account (`/account`, 🔑):** `PATCH /password`, `DELETE` (with `{ password }`; an admin gets `403 OwnAdminAccount`, §7), `GET /blocked-tags`, `PUT|DELETE /blocked-tags/{tagId}`; `GET /passkeys`, `POST /passkeys/options` (with `{ password }`), `POST /passkeys`, `PATCH|DELETE /passkeys/{passkeyId}`.
- **Lists (`/lists`, 🔑):** `GET`, `POST`, `PATCH|DELETE /{listId}`, `GET /{listId}/ids`, `GET /{listId}/media?limit&offset` (`Media[]`, `X-Total-Count`), `POST /{listId}/media` (`{ mediaIds }`, ≤ 2000), `PUT|DELETE /{listId}/media/{mediaId}`; `GET /media/{mediaId}/lists`. Someone else's list is a 404.
- **Saved searches (`/saved-searches`, 🔑):** `GET`, `POST`, `PATCH|DELETE /{searchId}`.
- **Users (`/users`, 🔒):** `GET`, `PATCH /{userId}` (`isAdmin`, `password`), `DELETE /{userId}`. There is always one admin left (`409 LastAdmin`), and an admin can't remove their own role (`403 OwnAdminRole`) or delete their own account (`403 OwnAdminAccount`): another admin does.
- **Media (`/media`):** `GET /media` (cached) is the one listing: `tags` (comma-separated names, a leading `-` excludes), or `untagged=true`; `limit` (1–100, default 40); and either `offset` (a numbered page: `X-Total-Count` with the total) or `cursor` (the `nextCursor` of the page before, for infinite scroll). It answers `{ items, nextCursor }`, and a numbered page carries the `nextCursor` to go on from. `GET /media/{mediaId}[?include=tags]`, `GET /media/random`, `GET /media/count`, `GET /media/tag-imports?ids=…` (≤ 200; how queued Danbooru lookups went), `POST /media/by-ids` (🔓 batch read, ≤ 200); `POST /media` (🔒 multipart upload = create → 201; thumbnails and, with `fetchTags`, Danbooru lookups are queued), `POST /media/bulk-delete` (🔒), `DELETE /media/{mediaId}` (🔒 → 204). Media-scoped tags: `GET /media/{mediaId}/tags` (🔓), **`PATCH /media/{mediaId}/tags`** and **`DELETE /media/{mediaId}/tags/{tagId}`** (🔓 — anyone may tag, see §7), `POST /media/{mediaId}/danbooru-tags` (🔒).
- **Tags (`/tags`):** `GET /tags` (🔓, cached) is one paged collection: `ids` or `names` (≤ 100 each), `search` (a picker's best matches: exact, then prefix, then containing), `filter` (a substring of the tag's or its category's name), `exclude`, `sort` (`tagName`, `categoryName` or `mediaCount`) and `direction`, `limit` (≤ 100) and `offset`, `include=counts` (adds `mediaCount` and `implicationCount`); it answers `Tag[]` with `X-Total-Count`. `GET /tags/{tagId}` (🔓); `POST /tags` (→ 201), `PATCH /tags/{tagId}`, `DELETE /tags/{tagId}`, `DELETE /tags/{tagId}/migrate-to/{targetTagId}` (migrate then delete), `POST /tags/{tagId}/migrate` (migrate, keeping the source tag) (🔒).
- **Tag categories (`/tag-categories`):** `GET` (🔓); `POST` (→ 201), `PATCH|DELETE /{categoryId}` (🔒).
- **Tag implications (`/tag-implications`):** `GET` (🔓); `POST` (→ 201), `DELETE /{tagId}/{impliedTagId}` (🔒).
- **Danbooru (`/danbooru`):** `GET` mappings (🔓); `POST` and `DELETE /category-mappings[/{danbooruCategoryId}]`, `POST`, `PATCH` and `DELETE /tag-mappings[/{mappingId}]` (🔒).
- **Duplicates (`/duplicates`):** `GET /report` (🔓), `POST /scan`, `POST /dismissals` (🔒).

### Response conventions

- **JSON is camelCase**, both ways, and so are query parameters and path parameters (`mediaId`, `nextCursor`, `fetchTags`); the columns stay snake_case, mapped in the Resources and Form Requests. A passkey ceremony's options keep WebAuthn's own member names, which are camelCase too, and go to the browser's WebAuthn call as they are.
- Reads return the resource or collection (a Resource, unwrapped). `POST` creates (201 with the resource) or runs a command; `PATCH` changes only the fields it sends; `DELETE` answers 204. A `PUT` with no body adds to a set (a blocked tag, a list item); no `PUT` makes a partial change.
- **Paging:** a paged table takes `?limit&offset` (`limit` at most 100) and sends its total in `X-Total-Count`; infinite scroll pages by `?cursor=`, each page carrying `nextCursor`. Filters are query parameters.
- A trailing slash is accepted: `/api/tags/` answers as `/api/tags`.
- Times are RFC 3339 in UTC, to the second (`2026-11-07T09:18:09Z`), as the database stores them (§4).
- **Path ids are bound to models** (`Route::pattern` + `Route::model` in `AppServiceProvider`, one line per parameter name): only digits match a route, and a missing record is a 404 with its resource's problem type (`MediaNotFound`, `TagNotFound`, `CategoryNotFound`, `MappingNotFound`), mapped from the model in `ApiExceptionRenderer`. A new `{…Id}` parameter needs its line there. An id in a body that names nothing is a 422.
- **Every error under `/api` is an RFC 9457 problem details body**, `application/problem+json`, whatever the request's `Accept`: `{ type, title, status, detail }`. `type` names the failure with a tag URI ending in its PascalCase name (`tag:gallery.mathdad.me,2026:MediaNotFound`, `ApiException::type()`), which the frontend branches on; `title` is the status's reason phrase; `detail` is the message to show. The app raises them as `throw new ApiException('CodeName', 4xx, 'message')`, and `ApiExceptionRenderer` gives the framework's failures the same shape: an unknown path is `404 NotFound`, a wrong method `405 MethodNotAllowed` (with `Allow`), anything uncaught `500 ServerError`, the rate limiters' `429` keeps `Retry-After`.
- **Statuses:** a failed validation is **422**, with the endpoint's own type (from its Form Request's `errorCodes()`) and `errors`, one `{ message, location }` per broken field (`body.tagName`, `query.limit`, `path.mediaId`); `getFieldError(e, 'tagName')` reads them on the frontend. A value over a limit is refused, never clamped. Malformed JSON is **400** `MalformedJson` (`RejectMalformedJson`). A conflict (a taken name, a duplicate mapping, an implication cycle) is **409**. A missing or failed Turnstile token is **403**.
- Danbooru failures: `429 DanbooruRateLimited`, `500 DanbooruAuthFailed` (the API refused the credentials) or `DanbooruNotConfigured`, and `503 DanbooruUnreachable`, `DanbooruUnavailable` or `DanbooruBlocked` (its firewall refused the server; the detail says why, with Cloudflare's Ray ID). An upload with `fetchTags` reports a failed lookup on the file as `tagsError`.
- Cached GETs add `X-Cache: HIT|MISS`. Rate-limit info is in `X-RateLimit-Remaining` / `Retry-After`.
- The public reads go through `cache.headers:no_cache;private;etag`: an `ETag`, and `304` for a matching `If-None-Match`. They are `private`, beyond the shared baseline's `no-cache`, because a signed-in browser's blocked tags change what the media listings hold (`App\Support\Viewer`). A new public GET goes inside that group in `routes/api.php`; the docs, the spec and `/media/random` stay outside it.
- Every action and schema is annotated with `#[OAS\*]` (swagger-php spec attributes, `use OpenApi\Spec as OAS`): request bodies in `app/OpenApi/Bodies/`, response shapes in `app/OpenApi/Schemas/` (the `Problem` schema among them), entities on their Resources, each property described, `required` and nullable as the API sends them. `tests/Support/OpenApiCommonErrors` adds the errors every operation can answer (429, a write's 400 `MalformedJson` and 403 `ForbiddenOrigin`, a secured one's 401). **Regenerate `openapi.json` (`composer docs`) and the frontend types (`npm run gen:types`) after any contract change**; CI fails if they drift.

## 6. Middleware stack (order matters; set in `bootstrap/app.php`)

1. **`SecurityHeaders`** (global, first): `X-Content-Type-Options: nosniff`,
   `Referrer-Policy: same-origin` and `X-Frame-Options: SAMEORIGIN` on every response, and the
   Content-Security-Policy (`App\Support\ContentSecurityPolicy`): the SPA shell's own nonce'd
   policy, the API reference's, and `default-src 'none'; frame-ancestors 'none'` on everything
   else (§7).
2. **CORS** (Laravel's `HandleCors`, global): origins from `GALLERY_ALLOWED_ORIGINS`; a preflight
   is answered `204` before anything else checks it.
3. **`VerifyOrigin`** (global): the CSRF check for `/api`. A POST, PUT, PATCH or DELETE is refused
   (`403 ForbiddenOrigin`) unless its `Origin`, or failing that its `Referer`, is allowed; a write
   with **neither** is refused too (it's a client working around CORS, such as curl). It runs
   before the rate limiter and authentication, so a refused request is neither counted nor told
   whether its session was signed in.
4. **The session** (the `api` group, first): `EncryptCookies`, `AddQueuedCookiesToResponse`,
   `StartSession`, then `AuthenticateSession`, which signs out a session (or a remember cookie)
   whose account's password changed since it signed in: that request is a 401.
5. **`PreventRequestForgery`**, origin-only (`preventRequestForgery(originOnly: true)`): a write
   the browser doesn't mark `Sec-Fetch-Site: same-origin` is `403 ForbiddenOrigin`, so no CSRF
   token is needed. Laravel skips it in tests; `ApiMiddlewareTest` switches it back on.
6. **`throttle:api`**: 120 requests a minute per visitor, then `429 RateLimitExceeded` with
   `Retry-After`. It runs before authentication (a priority list in `bootstrap/app.php`), so every
   request counts, signed in or not. A visitor is an IPv4 address, or an IPv6 client's /64
   (`App\Support\ClientAddress`, which the login and sign-up limits use too).
7. **`RejectMalformedJson`**: a body sent as JSON that doesn't parse is `400 MalformedJson`.
8. **`auth`** (the session guard) on the account and admin groups in `routes/api.php`: a request
   not signed in is `401 Unauthorized`, as problem details; there is no login page to redirect
   to.
9. **`admin`** (`EnsureAdmin`) on the admin group: an account that isn't an admin is
   `403 Forbidden`. It runs before the route's bindings, so it never says whether a record exists.

**The visitor's address** is restored once, by Apache: the vhost's `mod_remoteip` takes
`CF-Connecting-IP` from Cloudflare's published ranges only (`/etc/apache2/cloudflare-ips.txt`),
so `REMOTE_ADDR`, and with it `$request->ip()`, is the visitor. The app trusts no proxy and
reads no forwarding header: every limit above, and the login and sign-up limits, count per
visitor. The ranges file is refreshed when Cloudflare changes its list (cloudflare.com/ips).

Public reads take no auth middleware, but a signed-in browser's session still counts:
`App\Support\Viewer` finds the account, and its blocked tags leave the media listings.

The `web` group is empty: the SPA shell keeps no session and sets no cookies; only `/api` does.
`TrimStrings` and `ConvertEmptyStringsToNull` are removed, so a password or tag name arrives
exactly as sent.

---

## 7. Security model (and the one intentional exception)

Keep these invariants when editing auth or routes:

- **Every state-changing route needs a signed-in session**: an admin's write sits in the
  `['auth', 'admin']` group in `routes/api.php`, an account's own (its lists, blocked tags,
  searches, passkeys, password) in the `auth` group, and acts only on that account's rows;
  someone else's is a 404. A new write goes in one of the two. Signing in (password, sign-up or
  passkey) is a public write by nature.
- **The public writes**, deliberately outside the group, each its own route: `PATCH /media/{mediaId}/tags`
  and `DELETE /media/{mediaId}/tags/{tagId}` (anyone may tag media — an *intentional* design choice), and
  `POST /media/by-ids` (a read that uses POST only to carry a large id list). Being exact routes, a
  public sub-route can't widen a protected one (`DELETE /media/{mediaId}` stays 🔒). Don't add to them
  lightly. They still pass the origin check.
- **CSRF**: writes are refused unless the browser marks them `Sec-Fetch-Site: same-origin`
  (`PreventRequestForgery`), and unless `Origin` (or, failing that, `Referer`) is in
  `GALLERY_ALLOWED_ORIGINS` (`VerifyOrigin`); a write with neither header is refused. The session
  cookie is `SameSite=Lax` besides.
- **Sign-up and login** pass Cloudflare Turnstile first (`Services/Turnstile`, while `GALLERY_TURNSTILE_SECRET` is set): a token passes once, for its own form (`action`); a missing or failed one is `403` (`CaptchaRequired`, `CaptchaFailed`), and Cloudflare out of reach is `503 CaptchaUnavailable`, never a sign-in. Login is throttled to 10 attempts / 5 minutes per visitor (an IPv4 address or an IPv6 /64) and sign-up to 5 accounts an hour, on top of the global limit. Admins come from `gallery:user` or another admin. A wrong *current* password (changing it, deleting the account) is `403 IncorrectPassword`.
- **Sessions, not tokens** (since 7.0.0): a browser signs in with Laravel's session cookie,
  encrypted and **HttpOnly**, so no script on the page (Turnstile, an injected one) can read
  or copy it. Over https it is named `__Host-gallery-session`, so the browser takes it only from
  a secure page for this host alone. It lasts until the browser closes, and a day without a
  request ends it; "keep me signed in" adds a 30-day remember cookie (`auth.guards.web.remember`).
  Signing in moves the session to a new id (`App\Support\Login`), so one planted beforehand
  signs no one in; signing out ends this browser's session only. A new password
  (`User::setNewPassword()`: the account itself, an admin, or `gallery:user --password`) replaces
  the remember token and ends every other session at its next request (`AuthenticateSession`);
  the browser that changed its own password signs in again. Sessions are rows of the `sessions`
  table, stored under the SHA-256 of the session id (`HashedSessionHandler`, registered over
  Laravel's `database` driver in `AppServiceProvider`), so a copy of the database holds no session
  id; every API request writes its row. There are no bearer tokens: a script can't sign in.
- **Passwords** are argon2id at 64 MiB, one pass, four lanes (`config/hashing.php`; PHP's argon2
  from libsodium has one lane, and the stored hash then says `p=1`). The sign-in that proves a
  password replaces a hash made with other settings (`AuthController::login`, then
  `User::rehashPassword()`, which keeps the remember token); the account's other browsers sign
  in again at their next request, as after any change to the hash. `hashing.argon.verify` is off
  so that an account's bcrypt hash still checks. A login with a name no account has checks
  a stand-in hash made once, so it costs what a known name costs.
- **Admins** can't remove their own admin role (`403 OwnAdminRole`) or delete their own account
  (`403 OwnAdminAccount`, from `DELETE /users/{userId}` or `DELETE /account`): another admin does
  either. The last admin is `409 LastAdmin` first. `gallery:user --no-admin`, run on the host,
  can still demote the account it names, short of the last admin.
- **Passkeys** (`PasskeyController`, on `laravel/passkeys`' actions; the package's own routes are
  off): adding one needs the account's password; each challenge waits in the session for five
  minutes, answers once, and belongs to the account it was made for. A passkey belongs to
  `APP_URL`'s host and is checked against `GALLERY_ALLOWED_ORIGINS`. Signing in with one skips
  Turnstile but counts against the login limit; a refused one is `401 PasskeyNotAccepted`.
- **The Content-Security-Policy** is enforced on every page: `default-src 'self'; script-src 'self' 'nonce-…' https://challenges.cloudflare.com; style-src 'self' 'nonce-…'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; frame-src https://challenges.cloudflare.com`, with a fresh 128-bit nonce on every answer and never `'unsafe-inline'`. The build writes `__CSP_NONCE__` wherever a nonce goes (`html.cspNonce` in `vite.config.ts`: every script and link tag, and a `<meta property="csp-nonce">`), and `SpaController` replaces it; the page reads it back (`src/csp.ts`) for Turnstile's script and, through Reka's `ConfigProvider` at the app's root, for the style elements Reka adds. `/api/docs` has its own policy (Swagger UI's files, `img-src 'self' data:` for its icons). The only other host is Turnstile's, which the privacy page (`/privacy`) names; a new third party is added to the policy, the privacy page and `e2e/privacy.spec.ts` together. `GALLERY_CSP_REPORT_ONLY=true` turns the policy to report-only without a deploy (docs/ops.md, When the policy blocks something).
- **Only `public/` is served.** `.env`, the code, the data folders and `backups/` sit beside it, out of reach. A path that can only name a file (under `/assets/`, `/api-docs/`, `/media/full/`, `/media/thumbs/`, or a top-level name with a static extension) that isn't there is a 404, from `.htaccess` and again from the fallback route, never the SPA page that Cloudflare would cache as the file; every other path outside `/api` gets the page.

---

## 8. Frontend conventions

- **State**: Pinia stores (`stores/`). `gallery` holds `knownTags`, `categories`, `totalMedia`,
  `blurThumbnails` (kept in `localStorage` through `storage.ts`, whose `readPreference` and
  `writePreference` never throw, so a browser with storage blocked still works), and `lastViewedItemIds` (used for prev/next
  navigation on the media detail page). `auth` holds the signed-in `user` (`isAdmin`), asked of
  the server at start-up (`GET /auth/session`: the page can't read the session cookie);
  `account` its blocked tags and saved searches. `favorites` is the heart: this
  browser's ids in `localStorage` while signed out, the account's default list (and its other
  `lists`) while signed in; `signedIn()` moves the browser's into the account. `toast` drives
  `ToastContainer`; a toast's timer pauses while the pointer or focus is in the toast region, and
  one carrying an action (Undo) stays until it is used or dismissed.
- **Colour scheme**: `theme.ts` is the one module that sets it. The reader picks Match device, Light
  or Dark in the navbar's `ThemeMenu`; the pick is kept in `localStorage` (`theme`) and applied as
  `data-theme="light|dark"` on `<html>`, or no attribute to follow the device. `main.ts` imports
  it first. Every colour token in `tokens.css` is `light-dark(light, dark)` under `color-scheme:
  light dark`, which the attribute narrows to one scheme, and Bulma reads the same attribute. No
  style has a `prefers-color-scheme` query of its own. Vite's minifier lowers `light-dark()` to
  variables switched on `:root`, so in the built app an element's own `color-scheme` doesn't change
  its tokens: a surface dark in both schemes needs a token of its own (`--fav-on-scrim`).
- **Structure**: every page starts with a skip link to `<main id="main-content" tabindex="-1">`,
  and a navigation to another route moves focus to `main` (App.vue). The title is the page's
  `meta.title`, then `Gallery`. The navbar marks the current page with `aria-current="page"`,
  underlined as well as lit.
- **What a page shows depends on who is reading.** Signing in or out, and blocking a tag, call
  `gallery.listingsChanged()`: the count reloads, and a kept-alive `GalleryView` loads its page
  again when it is next on screen. Admin-only controls read `auth.isAdmin`; a page that needs an
  account or an admin wraps itself in `SignInGate` (`admin`), which offers a sign-in that comes
  back. Sign-up and sign-in render `TurnstileWidget` when `GET /auth/config` gives a site key,
  and reset it after every try (a Turnstile token passes once).
- **Passkeys** go through `composables/usePasskeys.ts` (`@simplewebauthn/browser` asks the
  browser). The login page has a "Sign in with a passkey" button and offers saved passkeys in the
  username field's autofill (`autocomplete="username webauthn"`), a request that waits until
  one is picked; asking with the button cancels it, and leaving the page does too
  (`cancelPasskeyRequest`). A closed prompt (`passkeyCancelled`) is not an error. The Account
  page lists them (`AccountPasskeys`).
- **The app never loads every tag.** There are thousands. `knownTags` holds the tags seen so far
  (by id), from searches and lookups: a picker searches the server (`store.searchTags`, or
  `useTagSearch` for a plain input), and a chip or a breadcrumb looks its tag up with
  `tagById`/`tagByName` after `loadTagsById`/`loadTagsByName`, in batches of 100. The Tags page
  pages, filters and sorts on the server (`GET /tags?include=counts&limit&offset…`).
- **Feedback**: a *toast* is the `toast` store → `ToastContainer`, and an *alert* is `AppAlert` (a failure inside the dialog that caused it, a warning on one section of a page). The shared rule that wording fitting either is a toast, said once, came from here: a dialog showed a green banner while an identical toast slid in beside it. Bulma's `.notification` is intentionally left unstyled so ad-hoc banners cannot creep back. Field validation sits beside its input on `.help.is-danger`.
- **Keep alerts and toasts looking unalike.** They must be tellable apart at a glance, since only
  one of them disappears by itself. A toast is an object *above* the page: neutral `--surface-1`,
  drop shadow, 3px severity stripe, close button. An alert is *in* the page: flat, no shadow, no
  border, no stripe, washed with `--<severity>-soft`. Severity rides the wash and the icon, never
  the words, so alert text stays on `--text-1`.
- **The toast viewport is fixed bottom-right at `z-index: 64`** — above dialogs (60/61), below the
  lightbox (70/71). It sets `pointer-events: none` with `pointer-events: auto` on each toast:
  Reka's modal Dialog puts `pointer-events: none` on `<body>` while open, which otherwise leaves
  Dismiss and Undo dead on any toast raised from inside a dialog, and without the `none` on the
  viewport its mostly-empty rectangle would swallow clicks meant for the page behind it.
- **API calls** go through `composables/useApi.ts` (`get/post/put/patch/del/upload`), using paths from
  `api/endpoints.ts`. The browser sends the session cookie with each request; `useApi` keeps no
  token. It reads every failure's problem details into a structured `ApiError` (`status`, `code`
  from the problem's `type`, `message` from its `detail`, and a validation's `fields` by field
  name), returns a paged read's `X-Total-Count` with `getPage`, reports a 401 to the auth store (`whenUnauthorized`: the session ended, so the app
  forgets the account), and resolves `204`/empty bodies to `undefined`. Don't call `fetch`
  directly in components.
- **Types are generated** from the OpenAPI spec into `types/api.generated.ts` (`npm run gen:types`);
  import domain types from `types/index.ts` (friendly `Required<>` aliases), never the generated file.
- **Lint, format, TypeScript and test settings** come from shared-tooling's `lint/web` package, `@jwill89/lint-web`, installed from `file:../shared/lint/web`. `eslint.config.ts` spreads its default, `vueConventions` and `tsStylistic` sets, then adds this project's own rules: the label rule's nesting option, Router's global components, and a ban on calling `fetch` outside `useApi.ts`. `prettier.config.js` re-exports the shared Prettier settings, and `tsconfig.{app,node}.json` extend the shared ones. A `catch (e)` reads the error with the `getError*` helpers in `useApi.ts`.
- **Stylelint** (`stylelint.config.js`, `npm run lint:css`) runs the shared config
  (`stylelint-config-standard`, with Vue SFC support) over the CSS and the SFC style blocks, and
  holds the palette rule (the shared `paletteRules`): no hex, and no `rgb()`/`hsl()` other than
  neutral white/black, anywhere but `src/tokens.css`, for which an `overrides` entry turns it off.
  `color-named` is on: neutrals are written `rgb(0 0 0 / a)` and `rgb(255 255 255)`, never by name. **Knip** (`knip.ts`, `npm run knip`) fails on unused
  files, exports and dependencies. Component specs mount a view with `@vue/test-utils` and fake
  the server by mocking `useApi` (`UploadView.spec.ts`).
- **Data-fetching composables**: `useGalleryData` (paginated lists), `useMediaTags` (detail + tag
  add/remove). Reuse them rather than re-implementing fetch logic.
- **Routing**: lazy-loaded views in `router/index.ts`; page title from `meta.title`.
  **Infinite scroll is a store preference** (`useGalleryStore().infiniteScroll`, persisted to
  `localStorage`, toggled from the navbar's view-mode select), *not* a URL param — `perPage=0` was
  the old sentinel and any `perPage < 1` is now coerced to 40, and one over 100 to 100
  (`constants/paging.ts`), the most `GET /media` takes. While scrolling, `GalleryView`
  mirrors your position into the URL via `router.replace`, and the reload watcher deliberately
  ignores page-only changes so that sync doesn't re-fetch. It starts from a numbered page and
  goes on with `GET /media?cursor=` from each batch's `nextCursor`, so items added meanwhile
  don't repeat ones above; scrolling up still loads numbered pages. The next batch is requested
  as soon as the one before it arrives, and reaching the sentinel uses that request.
- **Which navbar controls a route supports is declared on the route**, via `meta.showBlur` and
  `meta.showViewMode` (typed in `router/index.ts`). Add the flags when you add a route; don't
  reintroduce a chain of negations in `AppNavbar` — that's how the per-page select ended up live
  on `/favorites` (which has no pagination) and on the 404 page.
- **Styling**: the palette is `tokens.css` (`--surface-*`, `--text-*`, `--border*`, the semantic and
  tag colours), with the `--t-*` type scale, the `--sp-*` space scale and Bulma component overrides
  in `style.css`, written against those tokens — no raw colour outside `tokens.css`. Every text
  pair reaches AAA contrast in both schemes, which `e2e/contrast.spec.ts` measures from the live
  theme; a pair added to the palette is added to it. Chrome is deliberately neutral; there is no brand accent.
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
- **Shared UI**: `PageHeader` (title + breadcrumb + meta + actions — use it in every view so page
  titles stop drifting between sizes), `EmptyState`, `StatRow`, `TagCategoryLegend`.
- **Thumbnails**: derive from `fileName` by stripping the extension →
  `/media/thumbs/<base>.webp` (1x) and `/media/thumbs/<base>@2x.webp` (2x, used in `srcset`).
  `thumbnailUrl()` in `constants/thumbnails.ts` builds them; the cards, the prefetch and the
  service worker all go through that module.
- **Service worker** (`src/sw/`): cache-first for thumbs/static assets; everything else,
  the API included, goes straight to the network. Don't route the media listings through it:
  they queued infinite scroll's next batch behind the thumbnail work. Registered **in production
  only** (`import.meta.env.PROD` in `main.ts`) — a worker caching `/assets/*` in front of the Vite
  dev server makes local debugging depend on which copy it hands back. The app posts a
  `PrefetchThumbnailsMessage` to warm the *next* page (paged, `prefetchAdjacentPage`) or the *next
  batch* (infinite scroll, `prefetchNextBatch`) via `usePrefetch.ts`; keep both in `GalleryView`
  in sync. It sends only the size the cards load (it follows a shown card's `currentSrc`). The
  worker shares a download between the prefetch and the page (`inFlight`), and trims the
  thumbnail cache once per 100 downloads: listing its 2,000 keys holds up every read of that
  cache, and trimming after each download stalled a scroll for seconds. Bump `CACHE_VERSION` (in
  `cache.ts`) only when cached entries would be wrong for the new code: a bump empties every
  cache, thumbnails included, so the next scroll downloads them all again. Any change to the
  built `sw.js` installs on the next visit anyway, as long as nothing serves an old copy:
  `public/.htaccess` sends `/sw.js` with `Cache-Control: no-cache`, so Cloudflare checks with
  the server on every request (`cf-cache-status: EXPIRED`). Cloudflare caches `.js` files, and
  without that header it kept serving the old worker for up to two hours after a deploy.
- **The worker is TypeScript, built apart from the app.** `sw.ts` wires the worker's events;
  `cache.ts` does the caching using only what pages and workers share (`caches`, `fetch`,
  `Request`, `Response`), so `swCache.spec.ts` runs it against a fake cache. The
  `gallery-service-worker` plugin in `vite.config.ts` builds `sw.ts` after the app into
  `dist/sw.js`: one classic script at the root under a fixed name, since the browser registers
  and updates a worker by its URL. Built with the app, it would get a hashed name and import what
  it shares from another chunk. `tsconfig.sw.json` types it with the WebWorker lib, and
  `tsconfig.app.json` leaves out only `sw.ts`, so `cache.ts` and `constants/thumbnails.ts` are
  checked against both the page's and the worker's globals. Keep them that way. ESLint's
  no-`fetch` rule skips `src/sw/`.
- **Infinite scroll's bottom sentinel needs a top-up loop.** `IntersectionObserver` only reports
  intersection *changes*, so on a viewport tall enough that a fresh batch doesn't push the sentinel
  back out of view, no second callback arrives and loading stalls. `loadNextBatch` keeps pulling
  while `sentinelInTriggerZone()` holds; that helper and the observer share `SENTINEL_MARGIN` so
  they can't disagree. Also keep layout reads (`getBoundingClientRect`) inside the scroll handler's
  `requestAnimationFrame` — the cards use `content-visibility: auto`, and reading layout on every
  scroll event pins the main thread hard enough that the observer never fires.

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
  group**, or it serves stale data for up to the TTL. Deploys don't clear the cache: entries
  expire within a minute anyway, and a clear would also drop the rate limiter's counts and the
  queue's unique locks.
- **SQLite**'s connection settings are in `config/database.php`. One queue worker keeps writers few.
  Every API request writes its session row, so a write transaction held open for seconds (a job, a
  command) holds API requests behind it, and one held past `busy_timeout` (5 s) fails them: keep
  write transactions short, and don't wrap a long job's work in one.
- **Request cost** (measured on the droplet, 2026-11): a cached read takes about 10 ms, the
  1.5 MB tag list about 13 ms. Laravel's boot adds 3–5 ms over the Slim app it replaced, which
  is lost in a request's trip through Cloudflare.
- **The droplet runs Apache's event MPM with PHP-FPM; don't go back to prefork.** Each
  Cloudflare edge server opens its own connection to the origin. Under prefork with mod_php
  (until 2026-11-03), each Apache process sat out the 5 s keep-alive after answering one, so
  thumbnails Cloudflare hadn't cached came at about 8 a second: 120 took 15 s, and an API request
  waited behind them as long. Under event, 200 uncached thumbnails take 1.9 s, and the API
  answers in 0.27 s meanwhile. Cached thumbnails come back in about 0.16 s, however many are
  asked for at once. PHP-FPM's pool `www` (`/etc/php/8.5/fpm/pool.d/www.conf`, 12 processes)
  serves every PHP site on the droplet. PHP's errors still go to the vhost's `ErrorLog`, as
  `[proxy_fcgi:error] … AH01071: Got error 'PHP message: …'` (one request's messages on one
  line), and a copy goes to `/var/log/php8.5-fpm.log`. The way back is in
  `backups/fpm-switch/ROLLBACK.txt`.
- **Xdebug is installed on the droplet but switched off** (`phpdismod -v 8.5 xdebug`, 2026-11-03). It had been loaded for every PHP request, and its cost was most of a cached read: `/api/version` took 28 ms with it and 14 ms without. After debugging with it, restart `php8.5-fpm` and the `gallery-queue` worker once it is off again.
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

- **The schema is the migrations.** `SchemaTest` checks that a fresh `migrate` builds `tests/Fixtures/schema.sql`, CHECK constraints included, and that the live database's path, from the Slim app's schema (`slim-schema.sql`) with its legacy rows, migrates to the same schema with every row and time kept. A migration that changes a table also regenerates `schema.sql` (`php bin/schema.php`) in the same change, deliberately.
- **The 8.0.0 timestamp migration isn't additive.** It rebuilds eight tables to hold RFC 3339 times under a CHECK, and the code before it writes Unix seconds, which the CHECK refuses: rolling back past it needs the pre-deploy snapshot put back as well as the code (docs/ops.md, Upgrading to 8.0.0). A later rebuild follows database.md's procedure, as it does: foreign keys off outside one transaction (`$withinTransaction = false`), row counts compared, `PRAGMA foreign_key_check` before the commit.
- **The contract recordings are frozen at API 4.** `tests/Feature/Contract/ContractTest.php`
  replays every one of them (`tests/Fixtures/contract/`, format in its README), with
  `/api/version` pinned to the app and API versions they were made at, through
  `Tests\Support\Contract\Api8`, which translates each request and response into API 8's terms:
  camelCase, the listings' query parameters, PATCH for the partial PUTs, problem details and their
  statuses, RFC 3339 times, `X-Total-Count`. The fixtures' README lists every transform. The
  recorder went with the Slim app, so a recording is edited by hand only to correct it. A
  deliberate change to what the API answers that no transform covers goes in
  `tests/Feature/Contract/divergences.php` with its reason: an expected response the replay checks
  instead, or a `superseded` entry naming the feature test that covers it now (the replay skips
  it; a test checks the file exists).
- **PHP's upload limits fail quietly, so the upload page works within them.** `backend/public/.user.ini`
  (read by PHP-FPM, within 5 minutes of a change) sets `upload_max_filesize 100M` /
  `post_max_size 105M`, and `.htaccess` repeats them for mod_php. Keep its `php_value` lines
  inside `<IfModule php_module>`: without mod_php, Apache answers every request with a 500 when
  it meets one. `php.ini` keeps PHP's default
  `max_file_uploads = 20`. Past 20 files PHP drops the rest of a request, and past `post_max_size`
  the whole body, before the app sees it. So the upload page sends files in batches of at most
  20 files and 100 MB, and doesn't send a file over 100 MB at all (`constants/uploads.ts`, mirroring
  those settings: change both together). `UploadController::MAX_FILE_SIZE` still says 500 MB, but
  PHP's 100 MB applies first.
- **`ffmpeg` and `ffprobe` must be on the PATH** for thumbnails and video metadata (they run
  through Laravel's `Process`); without them the gallery shows its placeholder. **GD** (with WebP,
  ideally AVIF) is required for fingerprints and the duplicate scan's thumbnail comparison.
- **Danbooru's firewall wants the account in the User-Agent.** Requests are sent as
  `GalleryTagImporter/1.0; user #<GALLERY_DANBOORU_USER_ID>`; without the ID, Cloudflare blocks the
  server (`503 DanbooruBlocked`) before the API key is even checked. A 403 that isn't JSON is that
  block; the API's own refusals are JSON (`DanbooruAuthFailed`).
- **Settings come from `config()`, never `env()`.** Production runs on a cached config, where
  `env()` returns null outside `config/`. A new setting goes in `config/gallery.php` and
  `.env.example`, and on the droplet in `.env` followed by a deploy.
- **The queue worker runs old code until it restarts.** A deploy stops it before the migration
  and starts it on the new code; after any change made outside a deploy, run
  `systemctl restart gallery-queue`. A job already queued is unserialized with the
  class as it is when it runs, so rename a job's properties with care.
- **Tests sign in two ways.** Most act as an account directly (`asAccount()`, `asAdmin()` in
  `tests/Pest.php`, on Laravel's `actingAs`). The tests of signing in and out use a
  `Tests\Support\TestBrowser`, which keeps only the cookies the server set, so two browsers are
  two strangers. A test's requests share one app, whose session store would merge one request's
  sign-in into the next, so `Tests\TestCase::call()` empties it first, as a real server starts
  each request afresh. Passkey tests answer with `Tests\Support\FakeAuthenticator` (a real P-256
  key and signatures); PHP on Windows needs `tests/Fixtures/openssl.cnf` to make that key. The
  suite runs on array sessions and cheap argon2id (`phpunit.xml`); `SessionsTest` switches to the
  database driver, and `SignInConfigTest` reads the two config files with their real defaults.
- **The new code needs its tables the moment it serves a request**, and sessions are rows now: until
  the `sessions` table exists, every `/api` request fails. That is why the deploy takes the database
  snapshot before the swap and runs the migration first after it. A migration the running code
  depends on must stay additive, since the old code is still serving when the next one is
  prepared, and it is what the rollback puts back.
- **`hashing.argon.verify` stays off while any account may still hold a bcrypt hash.** With it on,
  checking one throws instead of failing, and nobody with an old hash could sign in. Whether any
  is left: `select count(*) from users where password like '$2y$%'`. It is the account's own
  password sign-in that replaces the hash (a remember cookie or a passkey does not).
- **The `__Host-` prefix is for https only.** A browser drops a cookie with that prefix unless it is
  Secure, has `Path=/` and no `Domain`, so `config/session.php` adds it only then; `http://localhost`
  development gets `gallery-session`. The OpenAPI document names the https one.
- **Never edit a vhost, the queue unit or the cron file on the droplet.** The next deploy installs
  `deploy/`'s over it, and (if it differs) says so. Change the template instead. If certbot
  ever writes a `-le-ssl` site beside the vhost, the next deploy disables it. A new cron file or
  worker unit is a template in `deploy/`, filled by `Expand-DeployTemplate` and named to
  `Install-HostConfig` in `scripts/deploy.ps1`; `docs/ops.md` says how they ship.
- **The worker and PHP-FPM write the same files.** The worker's account has www-data as its
  primary group and runs under `UMask=0002`; the shared folders are setgid and group-writable. The
  file cache's folders and files are made 0770 (`config/cache.php`) and the log 0664
  (`config/logging.php`): Laravel chmods a cache file whose mode differs, and a chmod of a file
  the process doesn't own throws, so the mode must be what both sides make. A folder the worker
  must write goes in the unit's `ReadWritePaths` too, or `ProtectSystem=strict` refuses it.
- **Apache's `Header` lines in `.htaccess` are `always`.** PHP-FPM's headers sit in the table
  `Header always` acts on, so a plain `Header set` adds a second copy of a header the app also
  sends. The folders and extensions `.htaccess` answers 404 for must match
  `SpaController::namesFile()`'s; a Pest test compares them. `/media` is the link to the media
  folder, so `DirectorySlash Off` for that one path keeps it the SPA's page.
- **The CSP nonce placeholder is `__CSP_NONCE__`** in `vite.config.ts` (`html.cspNonce`) and
  `SpaController::NONCE_PLACEHOLDER`; a Pest test and the e2e prepare script check they agree. A
  script or style element added at run time takes `cspNonce()` (`src/csp.ts`), or the policy
  refuses it; `e2e/fixtures.ts` fails any test that meets a refusal.
- **CI runs lint, static analysis, tests and build**, on shared-tooling's reusable workflows at
  the release `shared/` holds (`.github/workflows/ci.yml`; the docs job fails if the two differ).
  The backend job (`php.yml`) runs `composer audit` + `composer lint` (PHP CS Fixer) +
  `composer rector:check` + `composer analyse` (Larastan level 8 + strict and deprecation rules) +
  `composer docs:check` (regenerates `openapi.json`, fails on a diff from git's copy) + `composer test:coverage`
  (Pest, then the coverage floor). The frontend job (`web.yml`) runs `npm audit --omit=dev`, then
  `npm run gen:check` (regenerates `src/types/api.generated.ts`, fails on a diff) + `npm run lint`
  (ESLint, type-checked, incl. accessibility) + `npm run lint:css` (Stylelint) + `npm run knip` +
  `npm run format:check` (Prettier) + `npm run build` (vue-tsc + Vite) + `npm run test:coverage`
  (Vitest, failing below the floor in `vite.config.ts`). The scripts job (`pwsh.yml`) runs
  PSScriptAnalyzer with the shared settings over every tracked script. The docs job (`docs.yml`)
  runs markdownlint over every `.md` (`.markdownlint-cli2.jsonc`), checks `.editorconfig` and
  `.gitattributes` match shared-tooling's, and checks the workflow refs. The e2e job (`e2e.yml`)
  runs `npm run test:e2e` once the backend and frontend jobs pass: `frontend/playwright.config.ts`
  builds the SPA, and `e2e/server/prepare.ts` migrates a throwaway SQLite database, loads it with
  the contract fixture, puts the build's `index.html` where the deploy puts it and the rest of
  the build in a public folder of its own; PHP's built-in server then serves it all through
  Laravel's own router, so every page comes from `SpaController` under its real policy. Its settings
  (`e2e/env.ts`) override `backend/.env`, and the prepare script refuses to run if Laravel would
  use another database. Every main page must show its data and pass axe at WCAG 2.2 AA and its
  AAA rules (shared-tooling's `test/a11y`, `[...WCAG_22_AA, 'wcag2aaa']`) in both colour schemes,
  every text pair must reach AAA contrast (`contrast.spec.ts`), no page may meet a page error or
  a CSP refusal, every third party a page loads must be named on the privacy page, and a new
  account must keep a favorite across a reload. The specs find elements
  by role and name, never by a Bulma class, so they hold when the component kit replaces Bulma.
  Backend feature tests run
  on a fresh in-memory SQLite database per test (`RefreshDatabase`), with Danbooru (`Http::fake`),
  ffmpeg (`Process::fake`) and the queue faked where they're touched. Every answer a test gets
  from `/api` passes through `Tests\Support\OpenApiResponses` (from `TestCase::call()`): a 2xx
  must be a status its operation documents, and its JSON body must match the documented schema,
  every required field present, nothing undocumented, a null only where the field is nullable. A
  new endpoint or field is documented before its test can pass.
- **Deploys snapshot the database before migrating** — `scripts/deploy.ps1` writes `db/.backups/gallery-<UTC timestamp>.db.gz` with shared-tooling's `Backup-RemoteDatabase` (`sqlite3 .backup`, `PRAGMA integrity_check`, gzip, keeping five) before it swaps the code in, and aborts there if the snapshot fails. The snapshot is deliberately **not** auto-restored by the failure trap — that trap fires on any non-zero exit, including after a migration has already succeeded, and reverting data could discard media uploaded during the deploy window; a failed migration prints the path and the restore command (`rm -f <db>-wal <db>-shm && gunzip -c <snapshot> > <db>`, which keeps the file's owner) instead. The daily copies and the rest of the backups are in [docs/ops.md](docs/ops.md), with their restore steps.
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
- **`config.platform.php` in `backend/composer.json` is the droplet's PHP**, 8.5.12 as of 2026-11.
- **Converting a file's line endings does *not* fix line endings already escaped inside a generated artifact.** `openapi.json` once carried `\r\n` inside descriptions taken from docblocks, and needed `composer docs` to clear (the `OpenApiSpecTest` catches this).
- **Do not upgrade to TypeScript 7 yet.** TS 7 is the native port and removed the `./lib/tsc`
  subpath from its package `exports`. `vue-tsc` resolves that path directly and exits with
  `ERR_PACKAGE_PATH_NOT_EXPORTED`, so the type-check (`npm run build`) cannot run at all — and
  `vue-tsc`'s `typescript: ">=5.0.0"` peer range does not express the incompatibility, so npm will
  install the combination without complaint. Still true of 3.3.14 (2026-11): its only addition is
  following `typescript` when it is aliased to `@typescript/typescript6`. Stay on `~6.0.3` until
  vue-tsc ships TS 7 support.
- **Version bumps are the release trigger.** CI tags and publishes `v<version>` from `frontend/package.json` using the matching `## [<version>]` section of `CHANGELOG.md` as the release body. A release bumps the version in `frontend/package.json` and `package-lock.json` (`npm version x.y.z --no-git-tag-version`), the one place it is written: `config/gallery.php` reads it from `package.json` for `GET /api/version` (the deploy ships the release's copy beside the app; a checkout reads `../frontend/package.json`), and the footer from the build. It turns `## [Unreleased]` into its section with a compare link, and lands on `main`; once CI is green and has tagged it, `task deploy` ships that tag, and nothing untagged without `-Preview`. The **HTTP contract version is separate** — `App\OpenApi\OpenApiSpec::API_VERSION` — and only moves when the API surface changes; after moving it, run `composer docs`.

---

## 11. How to add things (quick recipes)

### A new API endpoint

1. Add the route to `routes/api.php`: public reads at the top, an account's own writes in the
   `auth` group, an admin's in the `['auth', 'admin']` group (a public write only
   deliberately, see §7).
2. For a write with a body, or a read with query parameters, add a Form Request extending
   `ApiRequest`: camelCase fields in `rules()`, `messages()` in the contract's wording, and
   `errorCodes()` for each rule's problem type and status (422 unless it is a conflict, 409).
3. Add the controller action: work through models and services, return a Resource (or
   `JsonResponse`) with the right status, raise failures as `ApiException`, and **annotate it with
   `#[OAS\Operation\Get/Post/…]`** and a readable `operationId`, its errors as `Problem`
   responses (+ a request body under `app/OpenApi/Bodies/` and any new response schema under
   `app/OpenApi/Schemas/`, every property described).
4. If it's a cached GET, use `$this->cache->remember()` (a public GET also goes in the
   `cache.headers` group for its ETag). A write through the models clears the cache by their
   events (§9); one that bypasses them calls `bump()` for the groups it changes.
5. Add a feature test (Pest) that goes through HTTP; fake Danbooru, ffmpeg or the queue if it
   reaches them.
6. Run `composer docs` (backend) and `npm run gen:types` (frontend); commit both regenerated files.
7. A paged table takes `limit` (at most 100) and `offset` and sends `X-Total-Count`; infinite
   scroll takes `cursor` and answers `nextCursor`.

### A new media field

A **migration** adding the column (and `php bin/schema.php` for `SchemaTest`'s expectation; a time is a `DATETIME` with the RFC 3339 CHECK), the `Media` model (`$fillable`,
casts), `MediaResource` (+ `#[OAS\Property]` on its schema), `MediaIngest`/`MediaMetadata` if it is
read at ingest (and `gallery:regenerate` to backfill), then regenerate the spec + types
(`composer docs`, `npm run gen:types`) so the frontend `Media` type picks it up.

### A new tag-category color

Add to `TagCategory::COLORS` (backend) **and** `VALID_COLORS` in `constants/categories.ts`
(frontend) **and** define its token in `frontend/src/tokens.css` (`light-dark()`, both schemes) and its classes in `style.css`; `contrast.spec.ts` gets its pairs.

### A new icon

Import it from the kit in `frontend/src/fontawesome.ts` and add it to `library.add(...)`, then
render it with `<font-awesome-icon icon="fa-solid fa-<name>" />` (or pass that string to `:icon`).
The kit (`@awesome.me/kit-4f2bd9b614`, shared with Fantasy Dossier and Home Library) holds the
classic solid and regular styles and brands, Pro icons included; a style it lacks is added to the
kit first. `icons.spec.ts` fails on an icon the source names that isn't registered, and on an
`<i class="fa-…">` element.

### A new background task

A job class (`ShouldQueue`) dispatched where the work starts; if it runs on a timetable, one line
in `routes/console.php`. Writing through the models clears the cached reads it changes (§9).

### A new setting

`GALLERY_<NAME>`, in `config/gallery.php` (read raw, as the others are), `phpunit.xml` (blanked), `.env.example` with a comment, the
`GalleryConfigTest` expectation, and §3's list above. On the droplet, add it to `.env` and deploy.

### A new frontend page

Create a view in `views/`, add a lazy route with `meta.title` in `router/index.ts`, fetch via
`useApi`/a composable, and link it from `AppNavbar.vue`.

---

## 12. Conventions checklist for any change

- [ ] JSON fields and query parameters are camelCase; times are RFC 3339 UTC.
- [ ] Writes are validated by a Form Request extending `ApiRequest`; failures are `ApiException`s
      with a PascalCase name (the problem's `type`) and a human message, answered as problem details.
- [ ] Responses go through Resources.
- [ ] Endpoints/schemas are `#[OAS\*]`-annotated with an `operationId`; `composer docs` +
      `npm run gen:types` re-run and the regenerated `openapi.json` / `api.generated.ts`
      committed (CI enforces).
- [ ] Writes go through the models so their events clear the cache (or `bump()` by hand, §9).
- [ ] New state-changing routes are in the admin group, or the account group acting only on the
      account's own rows (or public, consciously).
- [ ] Settings are read with `config()`, never `env()` outside `config/`.
- [ ] A rate limit counts a visitor by `ClientAddress::key()`, never by `$request->ip()`.
- [ ] A change to a vhost, the queue unit or the cron file is made in `deploy/` and ships with
      the next deploy, never on the droplet.
- [ ] Slow or per-file work runs in a queued job.
- [ ] A feature test covers it; the contract replay stays green, and a deliberate contract change
      is in `divergences.php`.
- [ ] Frontend: types imported from `types/`, API calls via `useApi` + `api/endpoints.ts`, no direct `fetch`.
- [ ] Frontend: colours from `tokens.css`'s tokens, each animation and transition with its own
      `prefers-reduced-motion` rule, no inline `<script>` or `style=""`; a new third party is named in
      the CSP and on the privacy page.
- [ ] Bump `CACHE_VERSION` (`src/sw/cache.ts`) only if cached entries would be wrong for the
      new code (a bump empties the thumbnail cache too).
- [ ] Keep the mirrored lists in sync: tag colors (`TagCategory::COLORS` ↔ `constants/categories.ts`),
      file types (`Media::IMAGE_EXTENSIONS`/`VIDEO_EXTENSIONS` ↔ `UploadView.vue`), upload limits
      (`public/.user.ini` + `.htaccess` ↔ `constants/uploads.ts`), and the static-file 404 lists
      (`.htaccess` ↔ `SpaController`).

---

## 13. Departures from the standards

Each holds for the reason it gives; [shared/standards/](shared/standards/README.md) wins everywhere else.

- **The remember token.** An account's remember token ("keep me signed in") is stored as issued, one for the account and the same in each browser it keeps signed in, since Laravel's remember cookie needs the plain token to issue another browser's (security.md, Sign-in and sessions).
- **Uploads.** An upload keeps its own file name (made safe, with `_1`, `_2` on a clash) and its original bytes, never a random name or a re-encoded image: the media are the owner's collected originals, matched to Danbooru by the original file's MD5, which re-encoding would change, and found in the media folder by their names (security.md, Input and output).
- **The queue unit's restart.** `deploy/gallery-queue.service` restarts with `Restart=always`, not `on-failure`, because `--max-time` ends the worker with status 0 every hour, which `on-failure` would not start again (deploy-and-ops.md, Services and systemd).
- **Laravel's own times.** The framework's tables keep the times the framework writes and compares: `sessions.last_activity`, and `jobs`' and `job_batches`' times, as integer seconds, and `failed_jobs.failed_at` in its `Y-m-d H:i:s` form. Every gallery and account table stores RFC 3339 (database.md, Timestamps).
- **Layout.** The pages lay out with Bulma's columns, `level` and spacing helpers beside `style.css`'s own classes, not with Tailwind or a stylesheet of object classes alone: replacing them is the move onto the component kit, which components.md keeps out of a pass that changes the backend, and this release moves the API to 8.0.0 (css.md, Layout; components.md, The kit first).
- **Markdown wrap.** Older paragraphs in this file, `README.md`, `CONTRIBUTING.md`, `backend/README.md` and `docs/` are hard-wrapped near 100 columns: rewrapping them all at once would bury real changes in the diff, and MD013 is off, so a paragraph is unwrapped when it is next rewritten (docs-and-agents.md, Markdown and copy).
- No restore drill is run on the host, by the owner's decision of 2026-11-15; the drill's steps stay in docs/ops.md for when one is wanted (deploy-and-ops.md, Restores).
- **Video captions.** The videos have no captions, transcripts or audio descriptions, which WCAG 2.2's 1.2.x criteria ask for from level A: they are third-party content, collected from elsewhere as they were published, so by the owner's decision of 2026-11-16 the Accessibility page (`/accessibility`, `AccessibilityView.vue`, linked from the footer) makes WCAG's statement of partial conformance for them instead (accessibility.md, Target and checks).

## 14. Not yet meeting the standards

- Since 2026-11-16: the queue worker's unit has no `MemoryMax=`: its measured peak (37.3 MB over 52 hourly runs, none of them ingesting) doesn't cover decoding the largest originals; waiting on the owner to measure the peak on the droplet after heavy work, as docs/ops.md's Upgrading to 8.0.0 says (deploy-and-ops.md, Services and systemd).
