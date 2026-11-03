# Gallery backend (Laravel)

The gallery's API and SPA shell, as a Laravel 13 app. It is being rebuilt from the Slim app
in `../backend-old/` on the `laravel-migration` branch; the plan, and how far it has got,
is in [docs/laravel-migration.md](../docs/laravel-migration.md). It must answer every
request the way the recordings in `../backend-old/tests/Fixtures/contract/` say the Slim
app does.

## Local setup

```bash
composer setup            # install, create .env from .env.example, generate APP_KEY
```

Then set `DB_DATABASE` in `.env` to an absolute path to a copy of the gallery database,
never a database another app is using. `database/gallery.db` is gitignored for that
purpose. Fill in the `GALLERY_*` and `DANBOORU_*` values as in `../backend-old/.env`.

Every path outside `/api` is the SPA shell (`SpaController`): the built `index.html` with
the page's link-preview tags. It reads `resources/spa/index.html`, which the deploy copies
from `../frontend/dist/`; copy it there yourself to try the shell locally. In development
the Vite dev server serves the SPA instead.

## Checks

CI's `backend` job runs each of these:

```bash
composer audit
composer lint             # Pint, PER preset (composer lint:fix applies)
composer rector:check     # PHP 8.5 idioms, dead code, Laravel rules (composer rector applies)
composer analyse          # Larastan, level 8 + strict and deprecation rules
composer docs             # rebuild openapi.json from the #[OAS\*] attributes; CI fails if it changes
composer test             # Pest
```

## Commands and background work

The work the Slim app did in `scripts/*.php` is Artisan commands. Most queue a job per file
or item, which a queue worker (`php artisan queue:work`) runs; each job logs its result.

```bash
php artisan gallery:ingest                # what cron.php did: add the files in the media folder, drop items whose files are gone
php artisan gallery:ingest --import-tags  # ...and import Danbooru tags for each new image (cron_with_tags.php)
php artisan gallery:import-tags [id]      # tag_imports.php: a Danbooru import for every item, or one; rate-limited
php artisan gallery:regenerate thumbnails # or fingerprints, or metadata (the regenerate-*.php scripts)
php artisan gallery:dupes                 # dupes.php, with the same --max-distance, --max-region-diff and --tune
```

`routes/console.php` schedules the ingest every night at midnight, in `SCHEDULE_TIMEZONE`,
and clears out expired login tokens daily; cron runs `php artisan schedule:run` every
minute. Thumbnails and video metadata need `ffmpeg` and `ffprobe` on the path.
