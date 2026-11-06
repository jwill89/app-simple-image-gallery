# Gallery backend (Laravel)

The gallery's API and SPA shell, as a Laravel 13 app. It replaced a Slim app in 5.0.0, matching
its API (version 4) first and then moving it to API 5 ([docs/laravel-migration.md](../docs/laravel-migration.md)
has how, and every deliberate difference). The tests replay the requests recorded from the Slim
app (`tests/Fixtures/contract/`), so nothing else drifts from what they recorded. Architecture
and conventions are in [AGENTS.md](../AGENTS.md).

## Local setup

```bash
composer setup            # install, create .env from .env.example, generate APP_KEY
```

Then set `DB_DATABASE` in `.env` to an absolute path to a copy of the gallery database,
never a database another app is using. `database/gallery.db` is gitignored for that
purpose. Each `task deploy` refreshes the copy `DB_DATABASE` names from the live database,
keeping the three previous ones beside it, unless run with `-NoDbPull`. Fill in the `GALLERY_*` and `DANBOORU_*` values; `.env.example` says what each is.

Every path outside `/api` is the SPA shell (`SpaController`): the built `index.html` with
the page's link-preview tags. It reads `resources/spa/index.html`, which the deploy copies
from `../frontend/dist/`; copy it there yourself to try the shell locally. In development
the Vite dev server serves the SPA instead.

## Checks

CI's `backend` job runs each of these:

```bash
composer audit
composer lint             # PHP CS Fixer, PER Coding Style 3.0 (composer lint:fix applies)
composer rector:check     # PHP 8.5 idioms, dead code, Laravel rules (composer rector applies)
composer analyse          # Larastan, level 8 + strict and deprecation rules
composer docs:check       # rebuild openapi.json from the #[OAS\*] attributes; fails if it changes
composer test:coverage    # Pest, then the coverage floor (94% of lines, 86% of methods)
```

The style, Rector and PHPStan settings start from shared-tooling's `lint/php` (the `shared/`
submodule at the repo root), and the coverage floor is its `test/php/coverage-check.php`.

## Commands and background work

Maintenance is Artisan commands. Most queue a job per file or item, which a queue worker
(`php artisan queue:work`) runs; each job logs its result.

```bash
php artisan gallery:ingest                # add the files in the media folder's root, drop items whose files are gone
php artisan gallery:ingest --import-tags  # ...and import Danbooru tags for each new image
php artisan gallery:import-tags [id]      # a Danbooru import for every item, or one; rate-limited
php artisan gallery:regenerate thumbnails # or fingerprints, or metadata: rebuild from each item's file
php artisan gallery:dupes                 # scan for duplicates; --max-distance, --max-region-diff, --tune
```

`routes/console.php` schedules the ingest every night at midnight, in `SCHEDULE_TIMEZONE`,
and clears out expired login tokens daily; cron runs `php artisan schedule:run` every
minute. Thumbnails and video metadata need `ffmpeg` and `ffprobe` on the path.
