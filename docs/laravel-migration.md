# Gallery Laravel Migration Plan

Nov 2, 2026 · @MathDad

Move the Gallery's Slim 4 backend to Laravel without changing its API contract, so the Vue front end
ships unchanged.

## Finish line

The migration is done when Laravel serves the same API, the front end hasn't changed, and the site
runs on the droplet with today's database and media.

Done means:

- `composer docs` regenerates `openapi.json` with no diff from today's file.
- `npm run gen:types` produces no diff, and nothing under `frontend/` was edited.
- Every endpoint has a Laravel feature test, and the recorded responses from the old app still
  match.
- CI is green with the Laravel versions of the backend checks.
- gallery.mathdad.me runs on Laravel with the existing `gallery.db` and `media/` intact.

What stays the same:

- **The API contract:** paths, methods, status codes, the `{ error, message }` error body with
  PascalCase codes, the `X-Cache` and `X-RateLimit-Remaining` headers, and 24-hour bearer tokens.
- **The data:** schema, rows and media files.
- **The domain code:** perceptual hashing, the LSH duplicate scan, `RegionDifference` and the ffmpeg
  thumbnail pipeline. These move into Laravel as services, not rewrites.

Left for after the migration: making uploads and the duplicate scan asynchronous (that changes the
contract), reconciling the 100 MB vs 500 MB upload limit, and trying Scramble for API docs.

## Component map

Every hand-built piece of the backend has a Laravel home; only the duplicate-detection code moves
over as-is.

### HTTP layer

| Today | In Laravel |
| --- | --- |
| Route groups in `api/index.php` | `routes/api.php` from `php artisan install:api`, which adds the `/api` prefix. Slim's `[/{per_page}]` becomes `{per_page?}`. |
| `$authMiddleware` and its public-write allowlist | `auth:sanctum` on protected routes only. The three public writes (`POST /media/by-ids`, `PATCH /media/{id}/tags`, `DELETE /media/{id}/tags/{tag_id}`) simply don't get it. |
| `auth_tokens` table and `AuthController::login` | Sanctum tokens on one seeded admin `User`. Login still checks `GALLERY_ADMIN_PASSWORD` with `hash_equals()` and returns `{ token }`. Tokens expire after 1,440 minutes. |
| `RateLimiter` on the `rate_limits` table | `RateLimiter::for('api')` at 120 per minute per IP, with `->response()` returning today's 429 body. Login's 10 per 5 minutes is checked in `AuthController` with the `RateLimiter` facade, so `X-RateLimit-Remaining` stays the API limit's, as recorded. |
| CSRF origin-check closure | A `VerifyOrigin` middleware on the `api` group, same logic. |
| CORS closure and OPTIONS catch-all | Built-in `HandleCors`, set up in `config/cors.php` from `GALLERY_ALLOWED_ORIGINS`. |
| Security-header closure | A small `SecurityHeaders` middleware. |
| `AbstractController::error()` and Slim's error middleware | Exception rendering in `bootstrap/app.php` that turns 404, 405, 422, 429 and 500 into `{ error, message }`. |
| Controllers in `api/Routes/Internal/` | `app/Http/Controllers/Api/`, with Form Requests for input and API Resources for output. |
| `index.php` (SPA shell, OG tags, FontAwesome kit) | A `SpaController` on `Route::fallback()` in `routes/web.php`. |

### Data layer

| Today | In Laravel |
| --- | --- |
| `DatabaseConnection` singleton and its PRAGMAs | The `sqlite` connection, with `DB_DATABASE` pointing at the existing `gallery.db`. Use the PRAGMA keys your `config/database.php` offers and run the rest from `AppServiceProvider`. |
| `Media`, `Tag`, `TagCategory`, `MediaCollection` and the repositories | Eloquent models: `Media` ↔ `Tag` through `media_tags`, `Tag` → `TagCategory`, and `Tag` ↔ `Tag` through `tag_implications`. Add `DanbooruTagMapping`, `DanbooruCategoryMapping` and `DismissedDuplicate`. |
| Non-standard table and key names | Set `$table`, `$primaryKey` (`media_id`, `tag_id`, `category_id`) and `$timestamps = false` on each model. |
| `ResponseCache` and `CacheGroup` | `Cache::remember()` on the file store. Cache tags don't work on file or database stores, so keep group invalidation with a version number per group in each key, bumped on writes. Keep the `X-Cache` header. |
| `MediaStorage` and the `media/` folders | A `media` disk, plus a `links` entry in `config/filesystems.php` so `/media/full` and `/media/thumbs` URLs stay the same. |
| `Configuration` reading `.env` | `config/gallery.php`. Code calls `config('gallery.…')`, never `env()`. |
| PHP-DI and `dependencies.php` | Laravel's container autowiring. Nothing to declare. |

### Background work and integrations

| Today | In Laravel |
| --- | --- |
| `scripts/cron.php` | A scheduled `gallery:ingest` command that dispatches a `ProcessNewMedia` job per file. |
| `regenerate-thumbnails.php`, `-fingerprints.php`, `-metadata.php` | `gallery:regenerate {thumbnails\|fingerprints\|metadata}`, dispatching jobs. |
| `dupes.php`, including `--tune` | `gallery:dupes` with the same options. |
| `tag_imports.php` and `cron_with_tags.php` | `gallery:import-tags`, dispatching `ImportDanbooruTags` jobs. |
| curl in `DanbooruTagger` | The Http client, with `Http::fake()` in tests. |
| `exec()` calls to ffmpeg | The `Process` facade. `Process::fake()` makes the thumbnail pipeline testable for the first time. |
| `DuplicateScanner`, `RegionDifference`, fingerprinting | Services in `app/Services/` with the same algorithms, so they give the same results, wired the Laravel way: the query builder where Eloquent would be slow, `config()`, and the configured paths. |
| Monolog `Logger` singleton | The `Log` facade. |

### Tooling

| Today | In Laravel |
| --- | --- |
| PHPStan level 8 with strict rules | Larastan at the same level. |
| Rector | Rector plus the rector-laravel rule set. |
| PHP CS Fixer (PER-CS 3.0) | Pint with the `per` preset, or keep CS Fixer. |
| swagger-php and `bin/openapi.php` | Keep both; point the scan at `app/`. |
| `DatabaseTestCase` and `schema.sql` | `RefreshDatabase` on in-memory SQLite, with model factories, written in Pest. |
| Phinx and `db/setup.php` | One baseline Laravel migration (see Phases). |

## Endpoint inventory

The API has 43 routes under `/api`. Every GET is public, and every write needs a token except the
three marked public. Each row is one feature test to write.

| Method | Path | Auth | Cache | Laravel action |
| --- | --- | --- | --- | --- |
| GET | `/version` | Public | | `SystemController@version` |
| GET | `/openapi.json` | Public | | `SystemController@openapi` |
| GET | `/docs` | Public | | `SystemController@docs` |
| POST | `/auth/login` | Public, login limit | | `AuthController@login` |
| GET | `/media/page/{page}/{per_page?}` | Public | 30 s | `MediaController@index` |
| GET | `/media/untagged/{page}/{per_page?}` | Public | 30 s | `MediaController@untagged` |
| GET | `/media/with-tags/{tag_list}/{page}/{per_page?}` | Public | 30 s | `MediaController@search` |
| GET | `/media/random` | Public | | `MediaController@random` |
| GET | `/media/count` | Public | 60 s | `MediaController@count` |
| POST | `/media/by-ids` | Public | | `MediaController@byIds` |
| POST | `/media/bulk-delete` | Token | | `MediaController@bulkDestroy` |
| POST | `/media` | Token | | `UploadController@store` |
| GET | `/media/{media_id}` | Public | | `MediaController@show` |
| DELETE | `/media/{media_id}` | Token | | `MediaController@destroy` |
| GET | `/media/{media_id}/tags` | Public | 30 s | `MediaTagController@index` |
| PATCH | `/media/{media_id}/tags` | Public | | `MediaTagController@store` |
| DELETE | `/media/{media_id}/tags/{tag_id}` | Public | | `MediaTagController@destroy` |
| POST | `/media/{media_id}/danbooru-tags` | Token | | `MediaTagController@importFromDanbooru` |
| GET | `/tags` | Public | 60 s | `TagController@index` |
| GET | `/tags/display` | Public | 60 s | `TagController@display` |
| GET | `/tags/{tag_id}` | Public | | `TagController@show` |
| POST | `/tags` | Token | | `TagController@store` |
| PUT | `/tags/{tag_id}` | Token | | `TagController@update` |
| DELETE | `/tags/{tag_id}` | Token | | `TagController@destroy` |
| DELETE | `/tags/{tag_id}/migrate-to/{target_tag_id}` | Token | | `TagController@destroy` |
| POST | `/tags/{tag_id}/migrate` | Token | | `TagController@migrate` |
| GET | `/tag-categories` | Public | | `TagCategoryController@index` |
| POST | `/tag-categories` | Token | | `TagCategoryController@store` |
| PUT | `/tag-categories/{category_id}` | Token | | `TagCategoryController@update` |
| DELETE | `/tag-categories/{category_id}` | Token | | `TagCategoryController@destroy` |
| GET | `/tag-implications` | Public | 60 s | `TagImplicationController@index` |
| POST | `/tag-implications` | Token | | `TagImplicationController@store` |
| DELETE | `/tag-implications/{tag_id}/{implied_tag_id}` | Token | | `TagImplicationController@destroy` |
| GET | `/danbooru/category-mappings` | Public | | `DanbooruCategoryMappingController@index` |
| POST | `/danbooru/category-mappings` | Token | | `DanbooruCategoryMappingController@store` |
| DELETE | `/danbooru/category-mappings/{danbooru_category_id}` | Token | | `DanbooruCategoryMappingController@destroy` |
| GET | `/danbooru/tag-mappings` | Public | | `DanbooruTagMappingController@index` |
| POST | `/danbooru/tag-mappings` | Token | | `DanbooruTagMappingController@store` |
| PUT | `/danbooru/tag-mappings/{id}` | Token | | `DanbooruTagMappingController@update` |
| DELETE | `/danbooru/tag-mappings/{id}` | Token | | `DanbooruTagMappingController@destroy` |
| GET | `/duplicates/report` | Public | | `DuplicateController@report` |
| POST | `/duplicates/scan` | Token | | `DuplicateController@scan` |
| POST | `/duplicates/dismissals` | Token | | `DuplicateController@dismiss` |

Routing notes:

- Id parameters take any segment, as Slim's did, so a malformed id gets the endpoint's own 400
  rather than a 404. Register a route with a fixed segment (`/media/random`, `/media/count`) before
  the one with a parameter in its place (`/media/{media_id}`).
- Test `{tag_list}` with commas, `-` exclusions, `+` and encoded characters, since Danbooru tag
  names can be unusual.
- The front end never sends trailing slashes, so Slim's optional `[/]` needs no Laravel equivalent.

## Phases

Record the old API first, then build Laravel beside it and port one endpoint group at a time until
every recording matches.

### Phase 0: Record the current contract

Controllers are untested today by design, so record what they return before touching anything.

- Branch `laravel-migration` off `main`.
- Build a small fixture database, `tests/Fixtures/contract.sql`: about 30 media rows, tags in three
  categories, some implications, Danbooru mappings and one dismissed pair. Add a few tiny image
  files for upload tests.
- Write a recorder script that runs the Slim app on the fixture with `php -S`, calls every route in
  the inventory, and saves status, body, and the `X-Cache`, `X-RateLimit-Remaining`, `Retry-After`
  and CORS headers. Each write runs on a fresh copy of the fixture.
- Record the error cases too: 401 with no token, 403 from a foreign origin, 404 for unknown ids, the
  400s each write returns for bad input, and 429 from both limiters.

**Done when:** every route and error case has a committed recording.

### Phase 1: Scaffold Laravel beside the old backend

- Create `backend-laravel/` with `composer create-project laravel/laravel`. Laravel 13 needs PHP 8.3
  or newer; the droplet runs 8.5.12. Copy the `config.platform.php` pin.
- Run `php artisan install:api` for Sanctum and `routes/api.php`.
- Add Pest 5, Larastan, rector-laravel, swagger-php and your formatter.
- Create `config/gallery.php` covering every variable in `.env.example`.
- Point the `sqlite` connection at a copy of the live database for local work.
- Add a CI job for `backend-laravel/` running audit, formatter, Rector, Larastan and Pest.

**Done when:** `php artisan about` runs and the new CI job passes.

### Phase 2: Schema, models and the baseline migration

- Write one baseline migration that creates each current table only if it's missing
  (`Schema::hasTable()`). It builds fresh test databases and does nothing on production, like
  `db/setup.php`'s Phinx baseline. It leaves out `auth_tokens` and `rate_limits`, which Sanctum and
  Laravel's rate limiter replace; production keeps them for the old app until the cleanup in Phase
  8.
- Copy the schema exactly: `COLLATE NOCASE` on `tag_name` and `category_name`, the composite keys on
  `media_tags`, `tag_implications` and `dismissed_duplicates`, the cascading foreign keys and every
  index.
- Add Laravel's own tables: `users`, `personal_access_tokens`, `jobs`, `failed_jobs` and
  `job_batches`.
- Build the models, relationships and factories, and a seeder that loads the contract fixture.
- Move the duplicate-detection and fingerprint classes over with their existing unit tests,
  converted to Pest with the Drift plugin (pest --drift). Add a test that a known image still
  produces its stored fingerprint, since old and new hashes can't be mixed.

**Done when:** a fresh `migrate` matches `backend-old/tests/Support/schema.sql` (less the two
replaced tables), and migrating a copy of the live database only adds Laravel's tables.

### Phase 3: Cross-cutting behavior

- Add an `ApiException` (code, status, message) and exception rendering that turns 404, 405, 429 and
  500 into `{ error, message }`.
- Add a base `ApiRequest` whose `failedValidation()` returns each endpoint's current error code and
  status. Laravel's default 422 with `{ message, errors }` would break the contract; this is the
  easiest trap to miss.
- Add the `VerifyOrigin` and `SecurityHeaders` middleware, the CORS config, and both rate limiters
  with today's 429 body.
- Port login: 10 attempts per 5 minutes per IP through the `RateLimiter` facade (a second throttle
  middleware would report its own `X-RateLimit-Remaining`), `hash_equals()` against
  `config('gallery.admin_password')`, 503 `AdminNotConfigured` when unset, and a 64-hex Sanctum
  token, the recorded format, on the admin user (created at first login) with a 1,440-minute expiry.
- Write a small cache helper: `remember(group, key, ttl, fn)` with a version number per group in the
  key, `bump(...groups)` for writes, and the `X-Cache` header.

**Done when:** the recordings for 401, 403, 429, CORS preflight and login all match.

### Phase 4: Port the endpoints, one group at a time

- Go in this order: system, tag categories, tags, tag implications, Danbooru mappings, media reads,
  media tags, duplicates, then uploads and deletes. Uploads go last because they touch files, ffmpeg
  and Danbooru.
- For each group: controller, Form Requests, API Resources that emit today's keys, swagger-php
  attributes, feature tests from the recordings, then `composer docs` and check the diff.
- Make the include/exclude tag search a query scope, and compare its SQL to today's with the query
  log. It's the busiest query in the app. (Done as `Media::taggedWith()`: on a copy of the live
  database it returns the same rows as the Slim query, and runs faster for searches with included
  tags and up to 2 ms slower for exclusions alone.)
- Every write bumps the same cache groups it invalidates today.

**Done when:** all 43 routes match their recordings and `openapi.json` has no diff apart from the
readable operationIds (see Contract changes).

### Phase 5: Commands, jobs and the scheduler

- Build the commands from the component map, and the `ProcessNewMedia` and `ImportDanbooruTags` jobs
  on the database queue.
- Give `ImportDanbooruTags` the `RateLimited` job middleware so a bulk import can't hammer Danbooru.
- Call ffmpeg through `Process` and Danbooru through `Http`, and fake both in tests.
- In `routes/console.php`, schedule `gallery:ingest` with `withoutOverlapping()` every night at
  midnight (in the droplet's timezone), the same schedule the droplet uses for `cron.php` now, plus
  `sanctum:prune-expired --hours=24` daily.

**Done when:** ingesting a copy of the live media folder gives the same rows, thumbnails and
fingerprints as `cron.php`.

How it was built, and where it differs from the Slim scripts:

- **Commands:** `gallery:ingest` (with `--import-tags` for what `cron_with_tags.php` did),
  `gallery:import-tags [id]`, `gallery:regenerate {thumbnails|fingerprints|metadata}` and
  `gallery:dupes`. The first three queue a job per file or item: `ProcessNewMedia`,
  `ImportDanbooruTags` and `RebuildFromFile`. `gallery:dupes` runs the scan itself and prints the
  tuning table.
- **One file at a time:** a file that can't be added fails only its own job and stays in the ingest
  folder for the next night. `cron.php` ran the whole batch in one transaction, so one bad file
  rolled back every row while the files already moved stayed in `full/`. A file still waiting from
  an earlier run is not queued twice (`ShouldBeUnique`).
- **Cache:** ingesting, regenerating and importing now invalidate the response-cache groups they
  change. The scripts left the lists stale until the cached responses expired.
- **Danbooru:** `--import-tags` uses the import rules in the database and the IQDB fallback, like
  every other import; `cron_with_tags.php` had its own hard-coded rules and looked up MD5 only.
  Imports are limited to 30 a minute. One that Danbooru answers 429 waits a minute and tries again;
  jobs retry for a day and give up after three exceptions. Results go to the log, not the console.
- **Checked:** the same sample ingest folder went through a staged copy of `cron.php` and through
  `gallery:ingest`. It held JPEG, PNG, WebP, GIF, BMP, animated GIF and video files, a file with a
  taken name, a copy of an existing item, an unsupported file, and 30 items whose files were gone.
  Both left the same rows, column for column, fingerprints included, and the same files. Neither
  machine had ffmpeg, so no thumbnails were compared; the tests check that ffmpeg and ffprobe are
  called with the arguments `cron.php` used. The run on a copy of the live media folder, with
  ffmpeg, is left for the droplet in Phase 7.

### Phase 6: SPA shell and API docs

- Port `index.php` to a `SpaController` on `Route::fallback()` that reads the built `index.html` and
  injects the OG tags and FontAwesome kit the same way.
- Add the `/api/docs` (Scalar) and `/api/openapi.json` routes.

**Done when:** the OG tags for `/media/{id}/tags`, a tag search, `/tags`, `/tags/{id}` and
`/favorites` match the old app's HTML.

How it was built and checked:

- **Recorded like the API.** The contract recorder now also serves the Slim app's `index.php` and
  records 11 `spa/` cases, using a fixed copy of the built `index.html`
  (`backend-old/tests/Fixtures/spa/`) and `Host: gallery.test`. They cover the home page, the
  gallery, a media item (tagged, untagged, a video, an unknown id, and a file name that needs
  encoding), tag searches with exclusions, `+` and HTML to escape, `/tags`, a tag, an unknown tag,
  `/favorites`, the FontAwesome kit, and a missing `index.html`. The replay checks each page's HTML
  byte for byte, and all 11 match. Re-recording changed none of the 180 API recordings.
- **Where the HTML comes from.** `SpaController` reads `resources/spa/index.html`
  (`gallery.spa_index`), outside `public/`, so Apache can never serve the page without its tags. The
  deploy copies it there from `frontend/dist`.
- **No sessions.** The `web` middleware group is empty: the shell sets no cookies and starts no
  session. The fallback route never matches `/api/…`, so an unknown API path is still a JSON 404, or
  405 when the path exists.
- **Media URLs.** `storage:link` creates `public/media`, linked to `gallery.media_path`, so
  `/media/full/…` and `/media/thumbs/…` keep their URLs.
- **Not ported:** `index.php`'s branch that replaced a FontAwesome kit script already in
  `index.html`. The built `index.html` has none, so the kit is always added before `</head>`, as the
  old app did for it.
- The `/api/docs` and `/api/openapi.json` routes were ported in Phases 3 and 4.

### Phase 7: Deploy and cut over

Follow the Deployment section below.

**Done when:** the live site runs on Laravel, admin login works, scheduled ingest runs and the
health check passes.

How it went (2026-11-03), and where it differs from the Deployment section:

- **Backed up first.** `/var/www/gallery-backups/20261103-042056/` (since moved to
  `/var/www/gallery.mathdad.me/backups/`) holds the database (`VACUUM INTO`, integrity checked), the
  Slim webroot as a tarball, the media as hard links, and both gallery vhost files.
- **The data stayed where it was.** Laravel's `.env` points `DB_DATABASE`, `GALLERY_MEDIA_PATH` and
  `GALLERY_DUPES_PATH` at the Slim webroot's `db/`, `media/` and `dupes/`, so not one of the Slim
  app's files changed. Going back was the vhost change alone, and both apps read the same data. The
  app ran from `/var/www/gallery-app/` until Phase 8 moved it into the domain folder, around the
  data.
- **A preview port, not a subdomain.** Before the switch the Laravel app was served on
  `127.0.0.1:8088`, reachable only from the droplet, so no DNS record or certificate was needed. It
  was removed after the switch.
- **Checked before the switch**, on the droplet:
  - The same 50 requests went to both apps: API reads, error cases, writes refused for a missing
    token, Origin or wrong password, SPA pages with their OG tags, media files and static assets. 47
    answered the same. Of the other three, two are the recorded divergences (an unknown `/api` path,
    a preflight). The third, `/media`, gets the same Apache 301 from both, differing only in the
    port named.
  - Phase 5's check, with ffmpeg: 25 live files of every kind in the gallery (JPEG, PNG, WebP,
    animated GIF, MP4, WebM), plus a taken name, a copy, an unsupported file and three items whose
    files were gone. They went through a staged copy of the live `cron.php` and through
    `gallery:ingest`. Both left the same 20 rows, column for column, and the same 57 files,
    thumbnails included, byte for byte.
- **The switch** changed only `DocumentRoot` and `<Directory>` in `gallery.mathdad.me.conf` (the
  http redirect) and `gallery.mathdad.me-le-ssl.conf`. The `Expires` headers, `Protocols` and
  certificate lines stay. After the switch: the API, the SPA with its OG tags, media with month-long
  `Expires`, 401 for a wrong password, http→https, and the site through Cloudflare all answered
  correctly.
- **Queue worker:** the systemd unit `gallery-queue` runs `php artisan queue:work --tries=3
  --max-time=3600` as `www-data` and restarts by itself, including after each deploy's
  `queue:restart`.
- **The scheduler is new here.** The droplet never scheduled `cron.php`: no crontab or systemd timer
  ran it, and ingest was run by hand. It's now in `/etc/cron.d/gallery-scheduler`, which runs `php
  artisan schedule:run` every minute as `www-data`, in a file of its own so no other site's crontab
  is touched. `gallery:ingest` runs at midnight UTC: the droplet's clock is UTC and
  `SCHEDULE_TIMEZONE=UTC`. `sanctum:prune-expired` runs at the same time.
- **Rollback** stops Laravel's background work too: disable `gallery-queue` and remove the
  scheduler's cron file (`ROLLBACK.txt` lists both).
- **Admin login** was tested on the live site after the switch, and it works.
- **A guard on ingest.** `gallery:ingest` now refuses to remove anything when none of the gallery's
  files are in `media/full`, which is what a wrong `GALLERY_MEDIA_PATH` looks like. Without the
  guard it would delete every row.
- **Logging:** an `ApiException` with a 4xx status is no longer reported. Only 5xx errors and
  uncaught exceptions reach the log.
- **Logins:** Slim's tokens don't carry over to Sanctum, so anyone logged in at cutover logs in once
  more.
- **Deploys** now go to `DEPLOY_APP_ROOT` (`scripts/deploy.ps1`). They snapshot the database into
  `db/.backups/` beside it. There is no `cache:clear`: cached responses expire within 60 s anyway,
  and a clear would also drop the rate limiter's counts and the queue's unique locks.

### Phase 8: Clean up

- Put the whole gallery in its domain folder, `/var/www/gallery.mathdad.me/`, with the Slim app kept
  in it as the rollback copy until it is deleted. (This replaced the plan's separate
  `/var/www/gallery-data/`: every folder in `/var/www` is named for its domain.)
- Move the contract fixtures the Laravel tests read into `backend/tests/`, then delete
  `backend-old/`.
- After a week live, add a migration that drops `auth_tokens`, `rate_limits` and `phinx_migrations`.
- Update README, AGENTS.md, CONTRIBUTING.md and CHANGELOG, and bump the app version. The API version
  stays the same because the contract didn't change.

**Done when:** the repo has one backend and its docs describe Laravel.

Progress:

- **`backend-old/` is gone** (2026-11-03). The fixtures the tests read moved, with their history, to
  `backend/tests/Fixtures/`: the recordings (`contract/`), `contract.sql`, the upload, thumbnail,
  SPA and dupes files, and the Slim app's `tests/Support/schema.sql` as `schema.sql`. The recordings
  are now frozen. Deleted with the rest of the Slim app: the recorder, its case list
  (`contract-cases.php`) and the Danbooru stub server. The contract README says how to check them
  out of git history to record again. The rest of the old tests went too; the Laravel suite and the
  replay cover what they checked.
- A checkout may still hold the Slim app's untracked files in `backend-old/` (`.env`, a database
  copy, `vendor/`). The root `.gitignore` ignores the whole folder so they can't be committed.
- **Everything is in `/var/www/gallery.mathdad.me/`** (2026-11-03), laid out as in Deployment below.
  The Laravel app moved in from `/var/www/gallery-app/`, the Slim code moved out of the folder's
  root into `slim-rollback/`, and the backups moved in from `/var/www/gallery-backups/`. The data
  didn't move, so the `.env` paths didn't change. A script did it in one pass on the droplet:
  - It checked first that nothing would clash and that the queue was empty.
  - It snapshotted the database, then moved the folders, with a link at the old path until the
    vhosts were switched.
  - It repointed the vhosts, the `gallery-queue` unit and the scheduler's cron file, and rebuilt
    Laravel's caches for the new path.
  - Checked afterwards: the live site answered, and `/.env`, the database, `composer.json`,
    `backups/` and `slim-rollback/` were not served. On a temporary local vhost, `slim-rollback/`
    still served the API, pages and media.

  `backups/ROLLBACK.txt` has the steps back, and `backups/rollback-vhosts/` has vhost files ready to
  point at `slim-rollback/`. The original vhost copies, now `apache-before-cutover/`, serve the
  folder root, which is the Laravel app now, so they must not be restored as they are.
- **Deploys narrowed their permissions.** They chown only the code, `vendor/` and `storage/` to
  `www-data`, never the data, `backups/` or `slim-rollback/`. The tarball, built on Windows, gave
  the code world-writable modes (`777`/`666`), which the old deploy kept too; the code is now
  `755`/`644`.
- **The docs describe Laravel, and the version is 5.0.0** (2026-11-03). README, AGENTS.md,
  CONTRIBUTING.md and `backend/README.md` were rewritten for the Laravel app and the droplet as it
  now runs, and CHANGELOG has the 5.0.0 entry. It's a major version because running the gallery
  changed: the server layout, a queue worker, a cron entry and new settings. The contract replay
  pins `/api/version`'s app version to the one the recordings were made at, so a release doesn't
  touch them.
- **API 5** (2026-11-03), folded into the 5.0.0 release: with the migration done, the API was
  changed to use what Laravel offers. What changed and why is under API 5 below. The API version is
  `5.0.0`; the replay pins `api_version` to `4.0.0`, as it pins the app version, since the
  recordings were made against API 4.
- Still to do, after a week live (from about 2026-11-10): delete `slim-rollback/` from the droplet
  (with `backups/rollback-vhosts/`, and the rollback steps in `backups/ROLLBACK.txt`), and add a
  migration that drops `auth_tokens`, `rate_limits` and `phinx_migrations`. That finishes Phase 8.

## Deployment

Laravel replaces the flat webroot with an app folder whose `public/` is the document root.
Everything for the gallery lives in its domain folder, as every site in `/var/www` does, and only
`public/` is served.

### Layout on the droplet

| Path in `/var/www/gallery.mathdad.me/` | Holds |
| --- | --- |
| `app/`, `bootstrap/`, `config/`, `database/`, `resources/`, `routes/`, `vendor/`, `artisan`, `composer.*`, `openapi.json` | The Laravel app's code, replaced by each deploy |
| `public/` | Apache's DocumentRoot: `index.php`, the built SPA assets, and the `media` link |
| `.env`, `storage/` | Config and Laravel's runtime files, kept across deploys |
| `db/`, `media/`, `dupes/` | The data: `gallery.db` (with `.backups/` snapshots), the media files and the ingest folder, the scan reports |
| `backups/` | The pre-cutover backup, `ROLLBACK.txt` and the vhost files for a rollback; root-only |
| `slim-rollback/` | The Slim app as it last ran, with links to `../db`, `../media` and `../dupes`, until Phase 8 deletes it |

### First cutover

The plan as written before the cutover. What was done instead, and why, is in Phase 7's notes and
Phase 8's progress; the layout above is the result.

1. **Move the data.** Snapshot the database with `VACUUM INTO`, then move `gallery.db` (with its
   `-wal` and `-shm` files), `media/` and `dupes/` into `/var/www/gallery-data/`. Leave symlinks in
   the old webroot so the old app keeps working, and confirm it still does.
2. **Deploy Laravel to `/var/www/gallery-app/`.** In `.env`, point `DB_DATABASE` and the media and
   dupes paths at the data folder. Run `php artisan storage:link` once to create `public/media`.
3. **Run `php artisan migrate --force`.** The baseline migration is a no-op on the live database and
   only adds Laravel's tables, so the old app is unaffected.
4. **Test on a temporary subdomain** with its own vhost pointing at `gallery-app/public`. Add that
   origin to `GALLERY_ALLOWED_ORIGINS` while testing writes.
5. **Switch.** Change the gallery vhost's DocumentRoot to `/var/www/gallery-app/public`, then run
   `apachectl configtest` and reload Apache. To roll back, point it at the old webroot and reload.
6. **Start background work.** Set `SCHEDULE_TIMEZONE` in `.env` to the droplet's timezone
   (`timedatectl`), so the ingest keeps `cron.php`'s midnight. Add the scheduler cron entry for
   `www-data` (`* * * * * cd /var/www/gallery-app && php artisan schedule:run >> /dev/null 2>&1`)
   and remove the old `cron.php` line. Run `php artisan queue:work --tries=3 --max-time=3600` as
   `www-data` under systemd or Supervisor so it restarts automatically. Before the switch, finish
   Phase 5's check on a copy of the media folder: run `cron.php` and `gallery:ingest` on the same
   files and compare the rows, fingerprints and thumbnails.

### Apache details

- The vhost needs `AllowOverride All` on `public/` for Laravel's `.htaccess`.
- Today's upload limits live in the old `.htaccess` (`upload_max_filesize 100M`, `post_max_size
  105M`, `max_execution_time 120`). Copy them into `public/.htaccess` or the vhost, or uploads will
  fall back to PHP's defaults.
- *After the cutover (2026-11-03):* the droplet moved from prefork with mod_php to the event MPM
  with PHP-FPM, for every site on it. Under prefork, each Apache process sat out the 5-second
  keep-alive after answering a Cloudflare connection, so thumbnails Cloudflare hadn't cached came at
  about 8 a second (120 took 15 s), and API requests waited behind them. Under event, 200 came in
  1.9 s, with the API answering in 0.27 s meanwhile. The upload limits moved to `public/.user.ini`,
  which PHP-FPM reads. `.htaccess` keeps them for mod_php inside `<IfModule php_module>`: without
  mod_php, a bare `php_value` makes Apache answer every request with a 500. PHP's errors still go to
  each vhost's `ErrorLog`, now as `[proxy_fcgi:error] … AH01071: Got error 'PHP message: …'`, with
  one request's messages on one line, and a copy goes to `/var/log/php8.5-fpm.log`. The way back is
  in `backups/fpm-switch/ROLLBACK.txt`.
- The workers and the scheduler must run as `www-data` so new thumbnails stay owned by Apache's
  user.

### Changes to deploy.ps1

- **Payload:** Laravel's `app/`, `bootstrap/`, `config/`, `database/`, `public/`, `resources/`,
  `routes/`, `artisan`, `composer.json`, `composer.lock` and `openapi.json`. Copy `frontend/dist`
  into `public/`, except `index.html`, which goes to `resources/spa/index.html`, where
  `SpaController` reads it.
- **Never shipped:** `.env`, `storage/` contents, `vendor/` and `bootstrap/cache/`.
- **Composer:** `composer install --no-dev --optimize-autoloader`. Dev dependencies are no longer
  needed on the server, since Phinx is gone.
- **Remote steps:** snapshot the database (in the folder that holds it), `php artisan migrate
  --force`, `php artisan storage:link`, `php artisan optimize --except=views` (there are no Blade
  views to cache), `php artisan queue:restart`, `chown` the code, `vendor/` and `storage/` to
  `www-data` (never the data or backups), then the same health check on `/api/media/count`.
- **Keep:** the code backup and rollback, the no-auto-restore rule for the database, and the
  two-connection limit for `ufw limit ssh`.
- **Dropped:** the `cache/api/*.cache` cleanup, with no `cache:clear` in its place (see Phase 7).

## Decisions and risks

The plan assumes the recommendations below; change any of them before Phase 1 starts.

### Decisions

| Decision | Recommendation | Why |
| --- | --- | --- |
| Laravel version | 13 | Current release; supports PHP 8.3–8.5, with security fixes until March 17, 2028. |
| Admin auth | Sanctum on one seeded admin user | It's what Laravel postings mean by API auth. The token stays opaque to the front end; existing logins end once at cutover. |
| Queue driver | `database` | Laravel's default; no Redis needed on the droplet. |
| Cache store | `file` | Matches today and keeps cache writes out of the SQLite file. Cache tags aren't available on either store. |
| Formatter | Pint with the `per` preset | Same PER-CS style you enforce now, with the tool Laravel teams use. |
| Test framework | Pest 5 | Built on PHPUnit 13 and needs PHP 8.4 or newer, which the droplet's 8.5.12 meets. Pest is common in Laravel shops, and its Drift plugin converts the existing tests. |
| API docs | Keep swagger-php | The spec diff is the finish line; Scramble would generate a different spec. |
| Repo layout | Laravel in `backend/`, the Slim app in `backend-old/` on a branch | The Slim app stays runnable as reference and for recording the contract, and is deleted at the end. Laravel is the only backend that ships from the branch. |

### Contract changes

Decided before Phase 3. The Laravel app answers these differently from the recordings on purpose,
and `backend/tests/Feature/Contract/divergences.php` holds the expected responses the contract
replay checks instead. Everything else recorded stays as recorded, quirks included: 400 for
non-numeric ids, `PUT` resetting omitted fields, 200 or 204 for ids that don't exist, `POST
/tags/{id}/migrate` keeping the source tag, and tag search accepting page 0.

| Recorded | Laravel | Why |
| --- | --- | --- |
| Every unmatched `/api` path is 405 with `Allow: OPTIONS` (Slim's OPTIONS catch-all) | 404 `NotFound` | The path doesn't exist; the method was never the problem. |
| Slim renders 405 and 500 as an HTML page, or `{ message }` for JSON | 405 `MethodNotAllowed` (with `Allow`) and 500 `ServerError`, as `{ error, message }` | One error shape for every failure, which the frontend already parses. |
| A CORS preflight is 200 | 204, from `HandleCors` | Laravel's built-in CORS handling. In production the SPA is same-origin and never preflights. |
| The login limit is about 10 per minute (the global limiter's cleanup deletes older rows) | 10 per 5 minutes | What the old code intended. |
| Renaming a tag onto an existing name is a 500 (UNIQUE constraint) | 400 `TagAlreadyExists` | What creating one already returns. |
| Dismissing a pair with an unknown media id is a 500 (foreign key) | 404 `MediaNotFound` | A missing resource, not a server fault. |

One change to the OpenAPI document, decided in Phase 4: each operation has a readable `operationId`
(`listTags`, `uploadMedia`, …) where swagger-php had hashed the Slim controller's class and method.
Moving the methods would have changed every hash anyway. Nothing else in `openapi.json` changed. The
frontend's `api.generated.ts` changed only in its `operations` keys, which no app code reads.

When a request has more than one problem, Laravel may report a different one first. A Form Request
checks the body before the controller looks up the id in the path, and it checks fields in the order
its rules list them. For example, a bad name sent to an unknown tag id is now a 400 for the name,
where the Slim app answered 404. The recordings only exercise one problem at a time.

### API 5

Made after the cutover (2026-11-03), once nothing had to match the Slim app any more. The recordings
are frozen at API 4, so a recorded case whose answer API 5 changes is marked superseded in
`divergences.php`, with the reason and the test that now covers it (the replay skips it, and a test
checks the named file exists). The replay also ignores two fields API 5 adds to answers it otherwise
leaves alone: `errors` on a validation failure and `next_cursor` on a page.

| API 4 | API 5 | Why |
| --- | --- | --- |
| No HTTP caching; the server cache's `X-Cache` only | The public reads send an `ETag` with `Cache-Control: no-cache, private` and answer `304` while unchanged (`cache.headers` middleware) | A revisit costs a status line instead of a body: the browser's own cache revalidates. (The service worker leaves the API alone; routing the listings through it made infinite scroll wait behind its thumbnail work.) |
| The app fetched every tag at start and filtered them in the browser; `/tags/display` was every tag | `GET /tags?search=…&limit=…&exclude=…` (exact, prefix, then containing), `?names=` and `?ids=` lookups; `/tags/display` is a page with `search`, `sort`, `direction` and `ids` | Thousands of tags. The store keeps the tags it has seen, by id. |
| An upload made thumbnails and asked Danbooru before answering | Both are queued; a file says `tags_status: "queued"` and `GET /media/tag-imports?ids=` reports the outcome (kept in the cache for a day) | A large batch with tags could outlast PHP's time limit, and one slow Danbooru reply held up every file. |
| Logging out only forgot the token in the browser | `DELETE /auth/token` revokes it | A copied token stopped working only when it expired. |
| A media page needed `/media/{id}` and `/media/{id}/tags` | `?include=tags` gives both in one | One round trip per detail page. |
| 400 for a non-numeric id, 200 or 204 for a missing one, several codes | Route model binding: digits only, and a missing record is a 404 with its resource's code | A missing thing should be a 404 that says what was missing. |
| `PUT` reset omitted fields to their defaults | `PUT` and `PATCH` change only what they send | An edit shouldn't need the whole record. |
| A validation failure named the first broken field | Also `errors`: every broken field's messages | A form can mark each field. |
| Infinite scroll asked for page numbers | `GET /media?cursor=…`, and each page's `next_cursor` | Page numbers shift when items are added, so a batch could repeat items already shown. |
| Each controller and job bumped the cache groups it remembered to | Model events clear them | A write from anywhere clears what it changes. |
| `POST /media/{id}/danbooru-tags` returned `all_tags` | Gone | Every tag in the gallery on each import, for a store that no longer holds them all. |

Kept as it was: `POST /tags/{id}/migrate` keeps the source tag, which its docs now say (they said it
deleted it).

Added after the cutover (2026-11-03), when Danbooru's Cloudflare firewall began blocking the
droplet. Both apps reported that block as `500 DanbooruAuthFailed`, telling you to check credentials
that were never even checked. A 403 whose body isn't JSON (the firewall's block page; the API itself
refuses in JSON) is now `503 DanbooruBlocked`. Its message says so and quotes Cloudflare's Ray ID
for Danbooru's admins. An upload with `fetch_tags` now gives each file whose lookup failed a
`tags_error` with that reason, where it used to say `tags_found: false`, as if Danbooru simply had
no post for it. The recordings are unchanged: their stub only ever refuses in JSON. The cause turned
out to be the User-Agent: Danbooru asks API clients to name the account in it
(`GalleryTagImporter/1.0; user #123`), and its firewall blocks requests that don't. The account's
numeric ID is now the `DANBOORU_USER_ID` setting, and a block with it unset says to set it.

The SPA shell's pages now carry `X-Frame-Options: SAMEORIGIN` and `X-Content-Type-Options: nosniff`,
which the Slim app set only on API responses. They come from the same global middleware, and keep
the gallery from being framed by another site.

Two smaller differences follow from using `HandleCors`, and the replay allows for both on every
response: `Access-Control-Allow-Headers`, `-Methods` and `-Max-Age` are sent only on preflights,
where browsers read them, and with exactly one allowed origin, every `/api` response names it in
`Access-Control-Allow-Origin`. A response the Slim app sent without `Cache-Control` may also carry
Symfony's default `no-cache, private`.

### API 6

Accounts (6.0.0, 2026-11-03) replaced the shared `GALLERY_ADMIN_PASSWORD`, so what this plan says
about the admin login (one seeded admin user, `hash_equals()` against the password,
`AdminNotConfigured`) describes API 4 and 5 only. Login now takes a username and password; the
recorded login cases are superseded in `divergences.php`, pointing at
`tests/Feature/Http/AccountsTest.php`. The replay signs in the recorder's admin as an account with
`is_admin` set. The rest of the contract is unchanged. AGENTS.md §5 to §7 describe the accounts, and
the CHANGELOG lists every change.

### API 7

Sessions and passkeys (7.0.0, 2026-11-03) ended the 24-hour bearer token this plan kept from the
Slim app, Sanctum with it. The token stayed through the move only because it was part of the
contract; with accounts, keeping it in the browser's storage let any script on the page copy a
login. A browser now signs in with Laravel's HttpOnly session cookie, and the API group keeps the
session. The recordings still carry the recorder's `Authorization: Bearer <admin-token>`; the
replay acts as the admin for a request that sends it, and as no one for any other token, which is
what the recordings expect of an expired or malformed one. The rest of the contract is unchanged.

### Risks

| Risk | Guard |
| --- | --- |
| Laravel's 422 validation response replaces today's 400s and error codes | The `ApiRequest` base class in Phase 3; the recordings catch any miss. |
| Fingerprints change and the duplicate scan silently breaks | Keep GD and the hashing code unchanged; test a known image against its stored fingerprint. |
| A write forgets to bump a cache group and serves stale lists for up to 60 s | Feature tests that write, then read, and expect fresh data. |
| Web requests, the scheduler and a queue worker contend for one SQLite file | WAL, `busy_timeout` 5000 and one worker. |
| `config:cache` makes `env()` return `null` outside config files | All settings go through `config/gallery.php`; Larastan can flag stray `env()` calls. |
| The droplet's PHP lacks an extension Laravel needs | Run `php -m` and check for Ctype, cURL, DOM, Fileinfo, Filter, Hash, Mbstring, OpenSSL, PCRE, PDO, Session, Tokenizer and XML before Phase 7. |
| Upload limits fall back to PHP defaults after the move | Copy the `php_value` lines into `public/.htaccess` or the vhost (since the move to PHP-FPM: `public/.user.ini`). |

## Kickoff in Claude Code

Export this doc as Markdown to `docs/laravel-migration.md`, link it from AGENTS.md, then start a
session with the prompt below. Run one phase per session and have it check that phase's "Done when"
line before you merge.

```text
Read AGENTS.md and docs/laravel-migration.md. We are doing Phase 0 only: record the current API contract. Don't change any existing app code.

On a new branch, laravel-migration:
1. Create backend/tests/Fixtures/contract.sql: a small fixture database built from the current Phinx schema, with about 30 media rows, tags in three categories, some implications, Danbooru mappings and one dismissed pair. Add a few tiny images for upload tests.
2. Write backend/bin/record-contract.php. It starts the Slim app on the fixture with php -S, calls every route in the plan's endpoint inventory (each write on a fresh copy of the fixture), and saves status, JSON body, and the X-Cache, X-RateLimit-Remaining, Retry-After and CORS headers to backend/tests/Fixtures/contract/.
3. Record the error cases listed under Phase 0.

Stop when the recordings are committed, and summarize what was recorded and anything you couldn't record.
```

## Sources

Project facts come from the Gallery repo itself: `backend/api/index.php`, `backend/index.php`,
`AGENTS.md`, `scripts/deploy.ps1` and `.github/workflows/ci.yml`.

- [Laravel 13 release notes and support policy](https://laravel.com/docs/13.x/releases)
- [Routing: API routes, rate limiting, CORS, fallback routes](https://laravel.com/docs/13.x/routing)
- [Sanctum](https://laravel.com/docs/13.x/sanctum)
- [Cache](https://laravel.com/docs/13.x/cache)
- [File storage](https://laravel.com/docs/13.x/filesystem)
- [Queues](https://laravel.com/docs/13.x/queues)
- [Task scheduling](https://laravel.com/docs/13.x/scheduling)
- [Deployment](https://laravel.com/docs/13.x/deployment)
- [Pint](https://laravel.com/docs/13.x/pint)
- [Database: SQLite configuration](https://laravel.com/docs/13.x/database)
- [Pest 5 upgrade guide: PHP and PHPUnit requirements](https://v5.pestphp.com/docs/upgrade-guide)
- [Pest: migrating from PHPUnit with Drift](https://pestphp.com/docs/migrating-from-phpunit-guide)
