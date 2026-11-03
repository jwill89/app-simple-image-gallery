# Gallery Laravel Migration Plan

Nov 2, 2026 · @MathDad

Move the Gallery's Slim 4 backend to Laravel without changing its API contract, so the Vue front end ships unchanged.

## Finish line

The migration is done when Laravel serves the same API, the front end hasn't changed, and the site runs on the droplet with today's database and media.

Done means:

- `composer docs` regenerates `openapi.json` with no diff from today's file.
- `npm run gen:types` produces no diff, and nothing under `frontend/` was edited.
- Every endpoint has a Laravel feature test, and the recorded responses from the old app still match.
- CI is green with the Laravel versions of the backend checks.
- gallery.mathdad.me runs on Laravel with the existing `gallery.db` and `media/` intact.

What stays the same:

- **The API contract:** paths, methods, status codes, the `{ error, message }` error body with PascalCase codes, the `X-Cache` and `X-RateLimit-Remaining` headers, and 24-hour bearer tokens.
- **The data:** schema, rows and media files.
- **The domain code:** perceptual hashing, the LSH duplicate scan, `RegionDifference` and the ffmpeg thumbnail pipeline. These move into Laravel as services, not rewrites.

Left for after the migration: making uploads and the duplicate scan asynchronous (that changes the contract), reconciling the 100 MB vs 500 MB upload limit, and trying Scramble for API docs.

## Component map

Every hand-built piece of the backend has a Laravel home; only the duplicate-detection code moves over as-is.

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

The API has 43 routes under `/api`. Every GET is public, and every write needs a token except the three marked public. Each row is one feature test to write.

| Method | Path | Auth | Cache | Laravel action |
| --- | --- | --- | --- | --- |
| GET | `/version` | Public |  | `SystemController@version` |
| GET | `/openapi.json` | Public |  | `SystemController@openapi` |
| GET | `/docs` | Public |  | `SystemController@docs` |
| POST | `/auth/login` | Public, login limit |  | `AuthController@login` |
| GET | `/media/page/{page}/{per_page?}` | Public | 30 s | `MediaController@index` |
| GET | `/media/untagged/{page}/{per_page?}` | Public | 30 s | `MediaController@untagged` |
| GET | `/media/with-tags/{tag_list}/{page}/{per_page?}` | Public | 30 s | `MediaController@search` |
| GET | `/media/random` | Public |  | `MediaController@random` |
| GET | `/media/count` | Public | 60 s | `MediaController@count` |
| POST | `/media/by-ids` | Public |  | `MediaController@byIds` |
| POST | `/media/bulk-delete` | Token |  | `MediaController@bulkDestroy` |
| POST | `/media` | Token |  | `UploadController@store` |
| GET | `/media/{media_id}` | Public |  | `MediaController@show` |
| DELETE | `/media/{media_id}` | Token |  | `MediaController@destroy` |
| GET | `/media/{media_id}/tags` | Public | 30 s | `MediaTagController@index` |
| PATCH | `/media/{media_id}/tags` | Public |  | `MediaTagController@store` |
| DELETE | `/media/{media_id}/tags/{tag_id}` | Public |  | `MediaTagController@destroy` |
| POST | `/media/{media_id}/danbooru-tags` | Token |  | `MediaTagController@importFromDanbooru` |
| GET | `/tags` | Public | 60 s | `TagController@index` |
| GET | `/tags/display` | Public | 60 s | `TagController@display` |
| GET | `/tags/{tag_id}` | Public |  | `TagController@show` |
| POST | `/tags` | Token |  | `TagController@store` |
| PUT | `/tags/{tag_id}` | Token |  | `TagController@update` |
| DELETE | `/tags/{tag_id}` | Token |  | `TagController@destroy` |
| DELETE | `/tags/{tag_id}/migrate-to/{target_tag_id}` | Token |  | `TagController@destroy` |
| POST | `/tags/{tag_id}/migrate` | Token |  | `TagController@migrate` |
| GET | `/tag-categories` | Public |  | `TagCategoryController@index` |
| POST | `/tag-categories` | Token |  | `TagCategoryController@store` |
| PUT | `/tag-categories/{category_id}` | Token |  | `TagCategoryController@update` |
| DELETE | `/tag-categories/{category_id}` | Token |  | `TagCategoryController@destroy` |
| GET | `/tag-implications` | Public | 60 s | `TagImplicationController@index` |
| POST | `/tag-implications` | Token |  | `TagImplicationController@store` |
| DELETE | `/tag-implications/{tag_id}/{implied_tag_id}` | Token |  | `TagImplicationController@destroy` |
| GET | `/danbooru/category-mappings` | Public |  | `DanbooruCategoryMappingController@index` |
| POST | `/danbooru/category-mappings` | Token |  | `DanbooruCategoryMappingController@store` |
| DELETE | `/danbooru/category-mappings/{danbooru_category_id}` | Token |  | `DanbooruCategoryMappingController@destroy` |
| GET | `/danbooru/tag-mappings` | Public |  | `DanbooruTagMappingController@index` |
| POST | `/danbooru/tag-mappings` | Token |  | `DanbooruTagMappingController@store` |
| PUT | `/danbooru/tag-mappings/{id}` | Token |  | `DanbooruTagMappingController@update` |
| DELETE | `/danbooru/tag-mappings/{id}` | Token |  | `DanbooruTagMappingController@destroy` |
| GET | `/duplicates/report` | Public |  | `DuplicateController@report` |
| POST | `/duplicates/scan` | Token |  | `DuplicateController@scan` |
| POST | `/duplicates/dismissals` | Token |  | `DuplicateController@dismiss` |

Routing notes:

- Id parameters take any segment, as Slim's did, so a malformed id gets the endpoint's own 400 rather than a 404. Register a route with a fixed segment (`/media/random`, `/media/count`) before the one with a parameter in its place (`/media/{media_id}`).
- Test `{tag_list}` with commas, `-` exclusions, `+` and encoded characters, since Danbooru tag names can be unusual.
- The front end never sends trailing slashes, so Slim's optional `[/]` needs no Laravel equivalent.

## Phases

Record the old API first, then build Laravel beside it and port one endpoint group at a time until every recording matches.

### Phase 0: Record the current contract

Controllers are untested today by design, so record what they return before touching anything.

- Branch `laravel-migration` off `main`.
- Build a small fixture database, `tests/Fixtures/contract.sql`: about 30 media rows, tags in three categories, some implications, Danbooru mappings and one dismissed pair. Add a few tiny image files for upload tests.
- Write a recorder script that runs the Slim app on the fixture with `php -S`, calls every route in the inventory, and saves status, body, and the `X-Cache`, `X-RateLimit-Remaining`, `Retry-After` and CORS headers. Each write runs on a fresh copy of the fixture.
- Record the error cases too: 401 with no token, 403 from a foreign origin, 404 for unknown ids, the 400s each write returns for bad input, and 429 from both limiters.

**Done when:** every route and error case has a committed recording.

### Phase 1: Scaffold Laravel beside the old backend

- Create `backend-laravel/` with `composer create-project laravel/laravel`. Laravel 13 needs PHP 8.3 or newer; the droplet runs 8.5.12. Copy the `config.platform.php` pin.
- Run `php artisan install:api` for Sanctum and `routes/api.php`.
- Add Pest 5, Larastan, rector-laravel, swagger-php and your formatter.
- Create `config/gallery.php` covering every variable in `.env.example`.
- Point the `sqlite` connection at a copy of the live database for local work.
- Add a CI job for `backend-laravel/` running audit, formatter, Rector, Larastan and Pest.

**Done when:** `php artisan about` runs and the new CI job passes.

### Phase 2: Schema, models and the baseline migration

- Write one baseline migration that creates each current table only if it's missing (`Schema::hasTable()`). It builds fresh test databases and does nothing on production, like `db/setup.php`'s Phinx baseline. It leaves out `auth_tokens` and `rate_limits`, which Sanctum and Laravel's rate limiter replace; production keeps them for the old app until the cleanup in Phase 8.
- Copy the schema exactly: `COLLATE NOCASE` on `tag_name` and `category_name`, the composite keys on `media_tags`, `tag_implications` and `dismissed_duplicates`, the cascading foreign keys and every index.
- Add Laravel's own tables: `users`, `personal_access_tokens`, `jobs`, `failed_jobs` and `job_batches`.
- Build the models, relationships and factories, and a seeder that loads the contract fixture.
- Move the duplicate-detection and fingerprint classes over with their existing unit tests, converted to Pest with the Drift plugin (pest --drift). Add a test that a known image still produces its stored fingerprint, since old and new hashes can't be mixed.

**Done when:** a fresh `migrate` matches `backend-old/tests/Support/schema.sql` (less the two replaced tables), and migrating a copy of the live database only adds Laravel's tables.

### Phase 3: Cross-cutting behavior

- Add an `ApiException` (code, status, message) and exception rendering that turns 404, 405, 429 and 500 into `{ error, message }`.
- Add a base `ApiRequest` whose `failedValidation()` returns each endpoint's current error code and status. Laravel's default 422 with `{ message, errors }` would break the contract; this is the easiest trap to miss.
- Add the `VerifyOrigin` and `SecurityHeaders` middleware, the CORS config, and both rate limiters with today's 429 body.
- Port login: 10 attempts per 5 minutes per IP through the `RateLimiter` facade (a second throttle middleware would report its own `X-RateLimit-Remaining`), `hash_equals()` against `config('gallery.admin_password')`, 503 `AdminNotConfigured` when unset, and a 64-hex Sanctum token, the recorded format, on the admin user (created at first login) with a 1,440-minute expiry.
- Write a small cache helper: `remember(group, key, ttl, fn)` with a version number per group in the key, `bump(...groups)` for writes, and the `X-Cache` header.

**Done when:** the recordings for 401, 403, 429, CORS preflight and login all match.

### Phase 4: Port the endpoints, one group at a time

- Go in this order: system, tag categories, tags, tag implications, Danbooru mappings, media reads, media tags, duplicates, then uploads and deletes. Uploads go last because they touch files, ffmpeg and Danbooru.
- For each group: controller, Form Requests, API Resources that emit today's keys, swagger-php attributes, feature tests from the recordings, then `composer docs` and check the diff.
- Make the include/exclude tag search a query scope, and compare its SQL to today's with the query log. It's the busiest query in the app. (Done as `Media::taggedWith()`: on a copy of the live database it returns the same rows as the Slim query, and runs faster for searches with included tags and up to 2 ms slower for exclusions alone.)
- Every write bumps the same cache groups it invalidates today.

**Done when:** all 43 routes match their recordings and `openapi.json` has no diff apart from the readable operationIds (see Contract changes).

### Phase 5: Commands, jobs and the scheduler

- Build the commands from the component map, and the `ProcessNewMedia` and `ImportDanbooruTags` jobs on the database queue.
- Give `ImportDanbooruTags` the `RateLimited` job middleware so a bulk import can't hammer Danbooru.
- Call ffmpeg through `Process` and Danbooru through `Http`, and fake both in tests.
- In `routes/console.php`, schedule `gallery:ingest` with `withoutOverlapping()` every night at midnight (in the droplet's timezone), the same schedule the droplet uses for `cron.php` now, plus `sanctum:prune-expired --hours=24` daily.

**Done when:** ingesting a copy of the live media folder gives the same rows, thumbnails and fingerprints as `cron.php`.

How it was built, and where it differs from the Slim scripts:

- **Commands:** `gallery:ingest` (with `--import-tags` for what `cron_with_tags.php` did), `gallery:import-tags [id]`, `gallery:regenerate {thumbnails|fingerprints|metadata}` and `gallery:dupes`. The first three queue a job per file or item: `ProcessNewMedia`, `ImportDanbooruTags` and `RebuildFromFile`. `gallery:dupes` runs the scan itself and prints the tuning table.
- **One file at a time:** a file that can't be added fails only its own job and stays in the ingest folder for the next night. `cron.php` ran the whole batch in one transaction, so one bad file rolled back every row while the files already moved stayed in `full/`. A file still waiting from an earlier run is not queued twice (`ShouldBeUnique`).
- **Cache:** ingesting, regenerating and importing now invalidate the response-cache groups they change. The scripts left the lists stale until the cached responses expired.
- **Danbooru:** `--import-tags` uses the import rules in the database and the IQDB fallback, like every other import; `cron_with_tags.php` had its own hard-coded rules and looked up MD5 only. Imports are limited to 30 a minute. One that Danbooru answers 429 waits a minute and tries again; jobs retry for a day and give up after three exceptions. Results go to the log, not the console.
- **Checked:** the same sample ingest folder went through a staged copy of `cron.php` and through `gallery:ingest`. It held JPEG, PNG, WebP, GIF, BMP, animated GIF and video files, a file with a taken name, a copy of an existing item, an unsupported file, and 30 items whose files were gone. Both left the same rows, column for column, fingerprints included, and the same files. Neither machine had ffmpeg, so no thumbnails were compared; the tests check that ffmpeg and ffprobe are called with the arguments `cron.php` used. The run on a copy of the live media folder, with ffmpeg, is left for the droplet in Phase 7.

### Phase 6: SPA shell and API docs

- Port `index.php` to a `SpaController` on `Route::fallback()` that reads the built `index.html` and injects the OG tags and FontAwesome kit the same way.
- Add the `/api/docs` (Scalar) and `/api/openapi.json` routes.

**Done when:** the OG tags for `/media/{id}/tags`, a tag search, `/tags`, `/tags/{id}` and `/favorites` match the old app's HTML.

### Phase 7: Deploy and cut over

Follow the Deployment section below.

**Done when:** the live site runs on Laravel, admin login works, scheduled ingest runs and the health check passes.

### Phase 8: Clean up

- Move the contract fixtures the Laravel tests read into `backend/tests/`, then delete `backend-old/`.
- After a week live, add a migration that drops `auth_tokens`, `rate_limits` and `phinx_migrations`.
- Update README, AGENTS.md, CONTRIBUTING.md and CHANGELOG, and bump the app version. The API version stays the same because the contract didn't change.

**Done when:** the repo has one backend and its docs describe Laravel.

## Deployment

Laravel replaces the flat webroot with an app folder whose `public/` is the document root. So the data moves to its own folder first, and cutover is a one-line Apache change you can reverse.

### Target layout on the droplet

| Path | Holds |
| --- | --- |
| `/var/www/gallery-data/` | `db/gallery.db`, `media/` and `dupes/`, shared by both apps during the switch |
| `/var/www/gallery-app/` | The Laravel app and its `.env` |
| `/var/www/gallery-app/public/` | Apache DocumentRoot: `index.php`, the built SPA assets, and a `media` link into the data folder |
| Today's webroot | The old app, kept untouched for rollback |

### First cutover

1. **Move the data.** Snapshot the database with `VACUUM INTO`, then move `gallery.db` (with its `-wal` and `-shm` files), `media/` and `dupes/` into `/var/www/gallery-data/`. Leave symlinks in the old webroot so the old app keeps working, and confirm it still does.
2. **Deploy Laravel to `/var/www/gallery-app/`.** In `.env`, point `DB_DATABASE` and the media and dupes paths at the data folder. Run `php artisan storage:link` once to create `public/media`.
3. **Run `php artisan migrate --force`.** The baseline migration is a no-op on the live database and only adds Laravel's tables, so the old app is unaffected.
4. **Test on a temporary subdomain** with its own vhost pointing at `gallery-app/public`. Add that origin to `GALLERY_ALLOWED_ORIGINS` while testing writes.
5. **Switch.** Change the gallery vhost's DocumentRoot to `/var/www/gallery-app/public`, then run `apachectl configtest` and reload Apache. To roll back, point it at the old webroot and reload.
6. **Start background work.** Set `SCHEDULE_TIMEZONE` in `.env` to the droplet's timezone (`timedatectl`), so the ingest keeps `cron.php`'s midnight. Add the scheduler cron entry for `www-data` (`* * * * * cd /var/www/gallery-app && php artisan schedule:run >> /dev/null 2>&1`) and remove the old `cron.php` line. Run `php artisan queue:work --tries=3 --max-time=3600` as `www-data` under systemd or Supervisor so it restarts automatically. Before the switch, finish Phase 5's check on a copy of the media folder: run `cron.php` and `gallery:ingest` on the same files and compare the rows, fingerprints and thumbnails.

### Apache details

- The vhost needs `AllowOverride All` on `public/` for Laravel's `.htaccess`.
- Today's upload limits live in the old `.htaccess` (`upload_max_filesize 100M`, `post_max_size 105M`, `max_execution_time 120`). Copy them into `public/.htaccess` or the vhost, or uploads will fall back to PHP's defaults.
- The workers and the scheduler must run as `www-data` so new thumbnails stay owned by Apache's user.

### Changes to deploy.ps1

- **Payload:** Laravel's `app/`, `bootstrap/`, `config/`, `database/`, `public/`, `resources/`, `routes/`, `artisan`, `composer.json`, `composer.lock` and `openapi.json`. Copy `frontend/dist` into `public/`, except `index.html`, which goes wherever `SpaController` reads it.
- **Never shipped:** `.env`, `storage/` contents, `vendor/` and `bootstrap/cache/`.
- **Composer:** `composer install --no-dev --optimize-autoloader`. Dev dependencies are no longer needed on the server, since Phinx is gone.
- **Remote steps:** snapshot the database (now in the data folder), `php artisan migrate --force`, `php artisan optimize`, `php artisan queue:restart`, `chown` `storage/` and `bootstrap/cache/` to `www-data`, then the same health check on `/api/media/count`.
- **Keep:** the code backup and rollback, the no-auto-restore rule for the database, and the two-connection limit for `ufw limit ssh`.
- **Replace** the `cache/api/*.cache` cleanup with `php artisan cache:clear`.

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

Decided before Phase 3. The Laravel app answers these differently from the recordings on purpose, and `backend/tests/Feature/Contract/divergences.php` holds the expected responses the contract replay checks instead. Everything else recorded stays as recorded, quirks included: 400 for non-numeric ids, `PUT` resetting omitted fields, 200 or 204 for ids that don't exist, `POST /tags/{id}/migrate` keeping the source tag, and tag search accepting page 0.

| Recorded | Laravel | Why |
| --- | --- | --- |
| Every unmatched `/api` path is 405 with `Allow: OPTIONS` (Slim's OPTIONS catch-all) | 404 `NotFound` | The path doesn't exist; the method was never the problem. |
| Slim renders 405 and 500 as an HTML page, or `{ message }` for JSON | 405 `MethodNotAllowed` (with `Allow`) and 500 `ServerError`, as `{ error, message }` | One error shape for every failure, which the frontend already parses. |
| A CORS preflight is 200 | 204, from `HandleCors` | Laravel's built-in CORS handling. In production the SPA is same-origin and never preflights. |
| The login limit is about 10 per minute (the global limiter's cleanup deletes older rows) | 10 per 5 minutes | What the old code intended. |
| Renaming a tag onto an existing name is a 500 (UNIQUE constraint) | 400 `TagAlreadyExists` | What creating one already returns. |
| Dismissing a pair with an unknown media id is a 500 (foreign key) | 404 `MediaNotFound` | A missing resource, not a server fault. |

One change to the OpenAPI document, decided in Phase 4: each operation has a readable `operationId` (`listTags`, `uploadMedia`, …) where swagger-php had hashed the Slim controller's class and method. Moving the methods would have changed every hash anyway. Nothing else in `openapi.json` changed. The frontend's `api.generated.ts` changed only in its `operations` keys, which no app code reads.

When a request has more than one problem, Laravel may report a different one first. A Form Request checks the body before the controller looks up the id in the path, and it checks fields in the order its rules list them. For example, a bad name sent to an unknown tag id is now a 400 for the name, where the Slim app answered 404. The recordings only exercise one problem at a time.

Two smaller differences follow from using `HandleCors`, and the replay allows for both on every response: `Access-Control-Allow-Headers`, `-Methods` and `-Max-Age` are sent only on preflights, where browsers read them, and with exactly one allowed origin, every `/api` response names it in `Access-Control-Allow-Origin`. A response the Slim app sent without `Cache-Control` may also carry Symfony's default `no-cache, private`.

### Risks

| Risk | Guard |
| --- | --- |
| Laravel's 422 validation response replaces today's 400s and error codes | The `ApiRequest` base class in Phase 3; the recordings catch any miss. |
| Fingerprints change and the duplicate scan silently breaks | Keep GD and the hashing code unchanged; test a known image against its stored fingerprint. |
| A write forgets to bump a cache group and serves stale lists for up to 60 s | Feature tests that write, then read, and expect fresh data. |
| Web requests, the scheduler and a queue worker contend for one SQLite file | WAL, `busy_timeout` 5000 and one worker. |
| `config:cache` makes `env()` return `null` outside config files | All settings go through `config/gallery.php`; Larastan can flag stray `env()` calls. |
| The droplet's PHP lacks an extension Laravel needs | Run `php -m` and check for Ctype, cURL, DOM, Fileinfo, Filter, Hash, Mbstring, OpenSSL, PCRE, PDO, Session, Tokenizer and XML before Phase 7. |
| Upload limits fall back to PHP defaults after the move | Copy the `php_value` lines into `public/.htaccess` or the vhost. |

## Kickoff in Claude Code

Export this doc as Markdown to `docs/laravel-migration.md`, link it from AGENTS.md, then start a session with the prompt below. Run one phase per session and have it check that phase's "Done when" line before you merge.

```text
Read AGENTS.md and docs/laravel-migration.md. We are doing Phase 0 only: record the current API contract. Don't change any existing app code.

On a new branch, laravel-migration:
1. Create backend/tests/Fixtures/contract.sql: a small fixture database built from the current Phinx schema, with about 30 media rows, tags in three categories, some implications, Danbooru mappings and one dismissed pair. Add a few tiny images for upload tests.
2. Write backend/bin/record-contract.php. It starts the Slim app on the fixture with php -S, calls every route in the plan's endpoint inventory (each write on a fresh copy of the fixture), and saves status, JSON body, and the X-Cache, X-RateLimit-Remaining, Retry-After and CORS headers to backend/tests/Fixtures/contract/.
3. Record the error cases listed under Phase 0.

Stop when the recordings are committed, and summarize what was recorded and anything you couldn't record.
```

## Sources

Project facts come from the Gallery repo itself: `backend/api/index.php`, `backend/index.php`, `AGENTS.md`, `scripts/deploy.ps1` and `.github/workflows/ci.yml`.

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
