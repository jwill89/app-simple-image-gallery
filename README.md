# Gallery

A self-hosted personal media gallery for images, GIFs, and videos, with a
Danbooru-style tagging system. It supports tag categories and implications,
include/exclude tag search, automatic tag importing from Danbooru, perceptual
duplicate detection, favorites, and admin-gated uploads.

> - **[AGENTS.md](AGENTS.md)** — in-depth architecture, data model, and conventions.
> - **[backend/API.md](backend/API.md)** — HTTP API reference. The live contract is the
>   OpenAPI 3.1 spec at `/api/openapi.json`, browsable at **`/api/docs`** (Scalar).
> - **[CONTRIBUTING.md](CONTRIBUTING.md)** — local setup, coding standards, and PR flow.
> - **[CHANGELOG.md](CHANGELOG.md)** — release history (current version: **3.0.0**).

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
- **Favorites** — stored locally in the browser.
- **Admin actions** — password-gated uploads and deletes via a bearer token.
- **PWA caching** — a service worker caches thumbnails and prefetches the next page.

## Tech stack

- **Backend:** PHP 8.5, [Slim 4](https://www.slimframework.com/), SQLite (WAL),
  [Phinx](https://phinx.org/) migrations, Monolog.
- **Frontend:** Vue 3 + TypeScript, Pinia, Vue Router, Vite, Bulma.
- **Media tooling:** `ffmpeg` (thumbnails), PHP GD + `intervention/imagehash`
  (fingerprinting / duplicate detection).

## Requirements

- PHP **8.5+** with `pdo_sqlite`, `curl`, `fileinfo`, `mbstring`, and **GD** (WebP, ideally AVIF)
  extensions. `composer install` refuses to run without them.
- `ffmpeg` available on the system `PATH` (used for thumbnail generation).
- Apache with `mod_rewrite` (the included `.htaccess` files drive routing).
- [Composer](https://getcomposer.org/) and [Node.js](https://nodejs.org/) **26+** (for building the frontend).

## Setup

1. **Install backend dependencies** (the PHP app lives in `backend/`)

   ```bash
   cd backend
   composer install
   ```

2. **Configure the environment** — copy `backend/.env.example` to `backend/.env` and fill it in:

   ```dotenv
   # Required: admin password for uploads/deletes. Login is refused if this is unset.
   GALLERY_ADMIN_PASSWORD=your-strong-password

   # Comma-separated list of allowed origins (CORS + CSRF check).
   GALLERY_ALLOWED_ORIGINS=https://gallery.example.com

   # Public base URL — required for the Danbooru IQDB fallback.
   GALLERY_URL=https://gallery.example.com

   # Optional: Danbooru API credentials for automatic tag importing.
   DANBOORU_LOGIN=your-danbooru-username
   DANBOORU_API_KEY=your-danbooru-api-key

   # Optional: FontAwesome kit ID (injected into <head>).
   FONTAWESOME_KIT_ID=abcdef1234
   ```

   Explicit OS/Docker environment variables take precedence over `.env`.

3. **Create the database** — Phinx migrations are the source of truth for the schema (run from `backend/`):

   ```bash
   php db/setup.php          # creates db/gallery.db (if needed) and runs all migrations
   # or, equivalently:
   php vendor/bin/phinx migrate
   ```

4. **Build the frontend**

   ```bash
   cd frontend
   npm install
   npm run build            # outputs to frontend/dist, which index.php serves
   ```

5. **Serve** `backend/` with Apache (PHP 8.5) — it is the app root (the deploy
   flattens it onto the droplet's webroot). The bundled `.htaccess` serves built
   assets and real files, routes `/api` to the Slim app, and falls back to
   `index.php` for the SPA (which also injects Open Graph meta tags).

## Development

Run the Vite dev server (proxies `/api` and `/media` to `http://localhost`, where
your PHP backend must be running):

```bash
cd frontend
npm run dev              # http://localhost:5173
```

### API contract & generated types

The API is a **hybrid REST-RPC** interface described by an **OpenAPI 3.1** spec
(generated from PHP attributes) and browsable via **Scalar** at `/api/docs`. After
changing any backend route or response shape, regenerate the spec and the frontend
types and commit both (CI enforces they stay in sync):

```bash
cd backend  && composer docs      # → backend/openapi.json (needs zircote/swagger-php)
cd frontend && npm run gen:types  # → frontend/src/types/api.generated.ts (openapi-typescript)
```

Frontend code imports domain types from `frontend/src/types` and endpoint paths
from `frontend/src/api/endpoints.ts` — never the generated file directly.

## Deploying

The app is deployed to the DigitalOcean droplet with [`scripts/deploy.ps1`](scripts/deploy.ps1)
(PowerShell + PuTTY `pscp`/`plink`, using the DigitalOcean `.ppk` key):

```powershell
.\scripts\deploy.ps1                              # build SPA, push code, composer install, migrate
.\scripts\deploy.ps1 -SkipBuild                   # redeploy current dist without rebuilding
.\scripts\deploy.ps1 -SkipComposer -SkipMigrate   # code-only push
```

It builds the frontend, then ships a tarball that **flattens `backend/` + `frontend/dist`** onto the
droplet's webroot — code only (never the database, `.env`, `vendor/`, or `media/`) — then on the host
runs `composer install` and database migrations (via `db/setup.php`,
which baselines a pre-Phinx database before applying pending migrations), restores ownership to
`www-data`, clears the API cache, and health-checks the API. A `.deploy-backup/` rollback copy is
kept on the host unless `-NoBackup` is passed. If a passphrase-protected key is used, load it into
Pageant first (`pageant.exe <key>`).

## Adding & maintaining media

Drop new files into the `backend/media/` input folder and run the ingest pipeline:

```bash
php backend/scripts/cron.php     # ingests media/ → media/full/, builds thumbnails + fingerprints,
                                 # and prunes DB rows whose files were deleted
```

Other maintenance scripts (run from anywhere; they `chdir` to the app root):

```bash
php backend/scripts/dupes.php                    # scan for duplicates → dupes/dupes-YYYY-MM-DD.json
php backend/scripts/dupes.php --tune --max-distance=8 --max-region-diff=off  # calibrate the scan (--help)
php backend/scripts/regenerate-thumbnails.php    # rebuild all thumbnails
php backend/scripts/regenerate-fingerprints.php  # rebuild perceptual fingerprints
php backend/scripts/regenerate-metadata.php      # backfill dimensions/duration/file size on existing media
php backend/scripts/tag_imports.php              # bulk Danbooru tag import
```

Schedule `cron.php` (and optionally `dupes.php`) via cron/Task Scheduler to
automatically ingest newly added files.

## Database migrations

The schema lives entirely in `backend/db/migrations/`. To change it (run from `backend/`):

```bash
php vendor/bin/phinx create AddSomethingUseful   # scaffold a migration
php vendor/bin/phinx migrate                      # apply
php vendor/bin/phinx rollback                      # revert the last migration
```

Do **not** hand-edit the schema elsewhere — add a migration.

## Security notes

- Set a strong `GALLERY_ADMIN_PASSWORD`. With it unset, login is refused (the
  insecure `changeme` default can never grant access).
- Keep `GALLERY_ALLOWED_ORIGINS` tight — it backs both CORS and the CSRF
  origin check. State-changing requests with no `Origin`/`Referer` are rejected.
- Login is rate-limited (10 attempts / 5 minutes per IP) on top of the global
  request limiter.
- `backend/.env`, `backend/db/gallery.db`, `backend/logs/`, `backend/cache/`, and
  `backend/dupes/` are gitignored and must never be committed.

## License

See [LICENSE](LICENSE).
