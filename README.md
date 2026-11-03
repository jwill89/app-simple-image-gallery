# Gallery

A self-hosted personal media gallery for images, GIFs, and videos, with a
Danbooru-style tagging system. It supports tag categories and implications,
include/exclude tag search, automatic tag importing from Danbooru, perceptual
duplicate detection, favorites, and admin-gated uploads.

> - **[AGENTS.md](AGENTS.md)** — in-depth architecture, data model, and conventions.
> - **API reference** — the live contract is the OpenAPI 3.1 spec at `/api/openapi.json`
>   ([backend/openapi.json](backend/openapi.json) in the repo), browsable at **`/api/docs`** (Scalar).
> - **[CONTRIBUTING.md](CONTRIBUTING.md)** — local setup, coding standards, and PR flow.
> - **[CHANGELOG.md](CHANGELOG.md)** — release history (current version: **5.0.0**).

---

## Features

- **Unified media** — images, animated GIFs, and videos in one browsable grid,
  with pagination or infinite scroll.
- **Tagging** — categories (with colors and descriptions) and **implications**
  (tag A auto-applies tag B, transitively).
- **Search** — filter by multiple tags, including exclusions (`-tag`).
- **Danbooru import** — auto-fetch tags by MD5 hash with an IQDB visual-similarity
  fallback; import rules are editable in the UI.
- **Duplicate detection** — perceptual hashing (LSH → Hamming), then a worst-region comparison that tells re-saved copies from variants.
- **Uploads** — any number of files at once (sent in batches the server accepts), with the
  outcome of each file and of its Danbooru lookup.
- **Nightly ingest** — files dropped into the media folder are added every night.
- **Favorites** — stored locally in the browser.
- **Admin actions** — password-gated uploads and deletes via a bearer token.
- **PWA caching** — a service worker caches thumbnails and prefetches the next page.

## Tech stack

- **Backend:** PHP 8.5, [Laravel 13](https://laravel.com/) (Eloquent, Sanctum, the database
  queue and scheduler), SQLite (WAL).
- **Frontend:** Vue 3 + TypeScript, Pinia, Vue Router, Vite, Bulma.
- **Media tooling:** `ffmpeg`/`ffprobe` (thumbnails, video metadata), PHP GD +
  `intervention/imagehash` (fingerprinting / duplicate detection).

## Requirements

- PHP **8.5+** with `pdo_sqlite`, `curl`, `fileinfo`, `mbstring`, and **GD** (WebP, ideally AVIF)
  extensions. `composer install` refuses to run without them.
- `ffmpeg` and `ffprobe` on the system `PATH` (thumbnails and video metadata).
- Apache with `mod_rewrite`, serving the app's `public/` folder.
- A queue worker and a cron entry for the scheduler (see [Background work](#background-work)).
- [Composer](https://getcomposer.org/) and [Node.js](https://nodejs.org/) **26+** (for building the frontend).

## Setup

1. **Install the backend** (the Laravel app lives in `backend/`)

   ```bash
   cd backend
   composer setup           # composer install, .env from .env.example, APP_KEY
   ```

2. **Configure the environment** — fill in `backend/.env` (every key is described in
   `.env.example`):

   ```dotenv
   # The SQLite database: an absolute path.
   DB_DATABASE=/var/www/gallery.example.com/db/gallery.db

   # Required: admin password for uploads/deletes. Login is refused while it is empty.
   GALLERY_ADMIN_PASSWORD=your-strong-password

   # Comma-separated list of allowed origins (CORS + CSRF check).
   GALLERY_ALLOWED_ORIGINS=https://gallery.example.com

   # Public base URL — required for the Danbooru IQDB fallback.
   GALLERY_URL=https://gallery.example.com

   # Where the media files and duplicate reports live (defaults: under storage/app).
   GALLERY_MEDIA_PATH=/var/www/gallery.example.com/media
   GALLERY_DUPES_PATH=/var/www/gallery.example.com/dupes

   # Optional: Danbooru API credentials for automatic tag importing. Danbooru asks API
   # clients to name the account in their User-Agent, so the user ID is needed too.
   DANBOORU_LOGIN=your-danbooru-username
   DANBOORU_API_KEY=your-danbooru-api-key
   DANBOORU_USER_ID=123456

   # Optional: FontAwesome kit ID (injected into <head>).
   FONTAWESOME_KIT_ID=abcdef1234
   ```

   Real environment variables take precedence over `.env`.

3. **Create the database** (from `backend/`):

   ```bash
   php artisan migrate
   php artisan storage:link   # public/media → GALLERY_MEDIA_PATH
   ```

4. **Build the frontend**

   ```bash
   cd frontend
   npm install
   npm run build            # outputs to frontend/dist
   ```

   Copy `frontend/dist/*` into `backend/public/`, except `index.html`, which goes to
   `backend/resources/spa/index.html`, where the SPA shell reads it. The deploy script does this.

5. **Serve** `backend/public/` with Apache (PHP 8.5, `AllowOverride All`, `FollowSymLinks`),
   preferably the event MPM with PHP-FPM: under prefork, each process sits out a request's
   keep-alive, and thumbnails queue. Its `.htaccess` serves real files (the built SPA, media) and
   sends everything else to Laravel: `/api` to the API, and every other path to the SPA shell,
   which adds each page's link-preview tags. PHP's upload limits are in `public/.user.ini`, which
   PHP-FPM reads; `.htaccess` repeats them for mod_php.

## Background work

- **Queue worker** — ingests, Danbooru imports and rebuilds run as queued jobs. Keep one worker
  running as the web server's user, under systemd or Supervisor:

  ```bash
  php artisan queue:work --tries=3 --max-time=3600
  ```

- **Scheduler** — the nightly ingest and token pruning. Add a cron entry for the same user:

  ```
  * * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
  ```

## Development

```bash
cd backend && composer dev     # php artisan serve, http://localhost:8000
cd frontend && npm run dev     # Vite, http://localhost:5173
```

The Vite dev server proxies `/api` and `/media` to `http://localhost`
(`frontend/vite.config.ts`), so serve the backend there, or point the proxy at `:8000`.

### API contract & generated types

The API is a **hybrid REST-RPC** interface described by an **OpenAPI 3.1** spec
(generated from PHP attributes) and browsable via **Scalar** at `/api/docs`. After
changing any backend route or response shape, regenerate the spec and the frontend
types and commit both (CI enforces they stay in sync):

```bash
cd backend  && composer docs      # → backend/openapi.json
cd frontend && npm run gen:types  # → frontend/src/types/api.generated.ts (openapi-typescript)
```

Frontend code imports domain types from `frontend/src/types` and endpoint paths
from `frontend/src/api/endpoints.ts` — never the generated file directly.

## Deploying

The app is deployed to the DigitalOcean droplet with [`scripts/deploy.ps1`](scripts/deploy.ps1)
(PowerShell + PuTTY `pscp`/`plink`, using the DigitalOcean `.ppk` key; host, user, key and app
folder come from the untracked `scripts/deploy.config.ps1`):

```powershell
.\scripts\deploy.ps1                              # build the SPA, push the code, composer install, migrate
.\scripts\deploy.ps1 -SkipBuild                   # redeploy the current dist without rebuilding
.\scripts\deploy.ps1 -SkipComposer -SkipMigrate   # code-only push
```

It ships the code only — never `.env`, `storage/`, `vendor/`, the database or the media. On
the host it:

1. builds the new code beside the live one (`composer install --no-dev` included), then swaps it
   in by renames, so the site never runs a half-replaced folder; the previous code goes to
   `.deploy-backup/`, put back if a later step fails (kept afterwards unless `-NoBackup`);
2. snapshots the database with `VACUUM INTO` and runs the migrations;
3. links the media folder and caches the config, routes and events;
4. restarts the queue worker and health-checks the API.

If a passphrase-protected key is used, load it into Pageant first (`pageant.exe <key>`).

## Adding & maintaining media

Drop new files into the media folder's root (`GALLERY_MEDIA_PATH`); the scheduler ingests them
at midnight. To ingest now, or run the other maintenance commands (from `backend/`, as the web
server's user):

```bash
php artisan gallery:ingest                 # add dropped files, remove items whose files are gone
php artisan gallery:ingest --import-tags   # … and import each new image's Danbooru tags
php artisan gallery:import-tags [id]       # import Danbooru tags for every item, or one
php artisan gallery:dupes                  # scan for duplicates → dupes/dupes-YYYY-MM-DD.json
php artisan gallery:dupes --tune --max-distance=8 --max-region-diff=off  # calibrate the scan
php artisan gallery:regenerate thumbnails  # or fingerprints, or metadata: rebuild from the files
```

The commands queue their work; the queue worker does it.

## Database migrations

The schema lives in `backend/database/migrations/`. To change it (from `backend/`):

```bash
php artisan make:migration add_something_useful   # scaffold a migration
php artisan migrate                               # apply
php artisan migrate:rollback                      # revert the last batch
```

Do **not** edit a migration that has already run in production — add a new one.

## Security notes

- Set a strong `GALLERY_ADMIN_PASSWORD`. With it empty, login is refused, so no default
  password can ever grant access.
- Keep `GALLERY_ALLOWED_ORIGINS` tight — it backs both CORS and the CSRF
  origin check. State-changing requests with no `Origin`/`Referer` are rejected.
- Login is rate-limited (10 attempts / 5 minutes per IP) on top of the global
  request limiter (120 a minute per IP). Login tokens are stored hashed and expire after 24 hours.
- Serve only `backend/public/`; the `.env`, the code and the data sit outside it.
- `backend/.env` and the database are gitignored and must never be committed.

## License

See [LICENSE](LICENSE).
