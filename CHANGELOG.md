# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

**Releases & tags.** Each version corresponds to a Git tag and GitHub Release
named `v<version>` (e.g. `v3.3.0`), created **automatically** by the CI `release`
job (`.github/workflows/ci.yml` → `.github/scripts/release.sh`): on a push to the
default branch, after the full gate passes, if the current version
(`frontend/package.json`) has no release yet it is tagged and released, with the
release body taken from that version's section below. So bump the version and
update its section here in the same commit and push a green commit — no manual
tagging.

## [Unreleased]

### Added

- **A daily database backup.** Each deploy now installs and checks a systemd timer
  (shared-tooling's `Install-BackupTimer`) that copies the database every day with
  `VACUUM INTO` to `db/backups/gallery-YYYY-MM-DD.db.gz`, checks the copy's integrity,
  compresses it, and keeps 28 days. Until now the database was copied only when a deploy
  migrated it.
- **The deploy pulls a copy of the live database** to the local one `backend/.env` names,
  keeping the three previous local copies; `-NoDbPull` skips it.
- **End-to-end tests** (`npm run test:e2e`, `task test:e2e`, and a CI job on shared-tooling's
  `e2e.yml`): Playwright opens each main page on the built SPA and the real API, on a
  throwaway database holding the contract fixture, and checks it with axe at WCAG 2.2 AA; a
  new account signs up, hearts an item and finds it in its favorites after a reload.

### Changed

- **Every database backup on the droplet is compressed** (shared-tooling 2.0.0). The deploy's
  snapshot before migrating is now shared-tooling's `Backup-RemoteDatabase`:
  `db/.backups/gallery-<UTC time>.db.gz`, integrity-checked before it is compressed, five kept.
  The uncompressed snapshots and daily copies already there count among those kept: the next
  deploy compresses the daily ones, and the old snapshots age out over five deploys. A failed
  migration prints the restore command with `gunzip -c`. Stylelint moves to 18.
- **The Vite dev server proxies only the media files** (`/media/full`, `/media/thumbs`), not
  all of `/media`, so reloading a gallery page such as `/media/1/40` gets the SPA instead of
  Laravel's shell. `GALLERY_BACKEND_URL` points the proxy at another backend, such as
  `php artisan serve`.
- **The first page appears without fading in.** Pages still fade from one to the next, but
  the first had faded in from nothing, its text too faint to read for a quarter-second.

### Fixed

What the new axe checks found, at WCAG 2.2 AA:

- **Every page has a `main` landmark** around its content, so a screen reader can jump to it.
- **The search box's icon-only buttons have names**: "Search" and "Clear search".
- **The sign-in and sign-up pages each have a level-one heading**, which looks as before.
- **Muted text is darker** (`--text-3`, light theme: #7d828a to #676c74), reaching 4.5:1 on
  every light surface, where it measured 3.3 to 3.9. The footer's version line uses it too,
  in place of Bulma's grey.
- **A link inside a sentence is underlined**, so it doesn't depend on colour alone.

## [7.0.1] - 2026-11-06

**Shared tooling.** The lint, test, CI and deploy settings now come from
[shared-tooling](https://github.com/jwill89/shared-tooling), the submodule at
`shared/` (v1.1.1), as they do in every project. Nothing the gallery serves
changes.

### Changed

- **The frontend's lint and format settings** come from shared-tooling's
  `@jwill89/lint-web`, replacing the `frontend/eslint-config` submodule: ESLint,
  Prettier, Stylelint and the TypeScript settings. Two rules are new here:
  `curly`, which put braces on every one-line `if`, and `noUncheckedIndexedAccess`,
  so each array or record index is now checked before use.
- **PHP CS Fixer replaces Pint**, with the shared PER Coding Style 3.0 rules; the
  code already met them, so no file changed. Rector and Larastan start from the
  shared sets.
- **Coverage floors.** CI fails below them: the backend's at 94% of lines and 86%
  of methods, the frontend's in `vite.config.ts`. The frontend tests run on jsdom
  instead of happy-dom, and PHPUnit now fails on a warning, deprecation or notice.
- **CI runs on the shared workflows** (PHP, web, PowerShell and docs). The docs
  job also checks `.editorconfig` and `.gitattributes` against shared-tooling's,
  and that the workflow refs match the submodule. Dependabot opens grouped weekly
  updates for Composer, npm and GitHub Actions.
- **The deploy runs on the shared SharedDeploy module:** one archive, which the
  host checks by SHA-256 and file count before anything changes, over one held
  SSH connection. The host builds the new code beside the live code and swaps it
  in, as before. After the swap, the deploy fetches `/api/media/count` and `/` from
  outside; if either fails, the previous code goes back. `task deploy` runs it.
- PSScriptAnalyzer and markdownlint use the shared settings, so
  `PSScriptAnalyzerSettings.psd1` and the frontend's `lint:md` script are gone
  (`task lint` runs both).

## [7.0.0] - 2026-11-03

**Sessions and passkeys.** A browser now signs in with a session cookie the page's
scripts can't read, instead of a token kept in the browser's storage, where any
script on the page could copy it. And an account can add passkeys, to sign in
with a phone, computer or password manager instead of its password. The HTTP
contract moves to **`7.0.0`**.

### Breaking (for running the gallery)

- **Everyone signs in again:** login tokens are gone, and their table with them.
- **Session settings** in `backend/.env`: `SESSION_DRIVER=file`,
  `SESSION_LIFETIME=1440` (a day without a request signs a session out) and
  `SESSION_EXPIRE_ON_CLOSE=true`. The cookie is Secure whenever `APP_URL` is
  https. Sessions are files in `storage/framework/sessions`.
- **Passkeys belong to `APP_URL`'s host**, so it must be the address people use.

### Breaking (for API clients — the contract is `7.0.0`)

- **No bearer tokens.** `POST /auth/login` and `POST /auth/register` answer
  `{ user }` and set the session cookie (HttpOnly, `SameSite=Lax`), which the
  browser sends with every request.
- **`GET /auth/session`** (public) says who the browser is signed in as, or
  `{ "user": null }`; it replaces `GET /auth/me`. **`DELETE /auth/session`** signs
  out, replacing `DELETE /auth/token`.
- **A write must come from the page itself:** one the browser doesn't mark
  `Sec-Fetch-Site: same-origin` is `403 ForbiddenOrigin`, as one from an origin
  that isn't allowed already was.

### Added

- **Passkeys.** The Account page adds them (the password first), and renames
  and removes them; an unnamed one is named after where it is kept (Windows
  Hello, iCloud Keychain, …). The login page signs in with one, from a button or
  from the username field's suggestions. API: `POST /auth/passkey/options` and
  `POST /auth/passkey` to sign in; `GET|POST /account/passkeys`,
  `POST /account/passkeys/options` and `PATCH|DELETE /account/passkeys/{passkey_id}`.
  Signing in with a passkey skips Turnstile and counts against the login limit.

### Changed

- "Keep me signed in" is a 30-day remember cookie; without it, the browser is
  signed out when it closes.
- Laravel Sanctum is gone. New: `laravel/passkeys` (on `web-auth/webauthn-lib`)
  and `@simplewebauthn/browser`.

### Security

- **The page's scripts can't read the login.** The token used to sit in
  `localStorage` or `sessionStorage`, readable by every script on the page (the
  FontAwesome kit and Turnstile's among them), and it worked from any machine for
  up to 30 days. The session cookie is HttpOnly.
- Signing in starts a new session id, so one planted beforehand signs no one in.
- A new password replaces the account's remember cookies and ends its other
  sessions at their next request.

## [6.0.0] - 2026-11-03

**Accounts.** Anyone can make one, with a username and password, behind
Cloudflare Turnstile. An account keeps favorites on the server, in lists of its
own; blocks tags, whose media then leaves its listings; and saves searches. An
admin is an account with the admin role, which replaces the shared admin
password. The HTTP contract moves to **`6.0.0`**.

### Breaking (for running the gallery)

- **`GALLERY_ADMIN_PASSWORD` is gone.** Make your admin account after deploying:
  `php artisan gallery:user <name> --admin` (it asks for the password). Signing
  up never makes an admin.
- **New settings:** `GALLERY_ALLOW_SIGNUP` (on unless `false`), and
  `CLOUDFLARE_TURNSTILE_SITEKEY` / `CLOUDFLARE_TURNSTILE_SECRET` (no secret, no
  bot check).
- Everyone signs in again: the migration replaces the users table.

### Breaking (for API clients — the contract is `6.0.0`)

- **`POST /auth/login` takes `{ username, password, remember? }`** and answers
  `{ token, user }`. A wrong username or password is `401 InvalidCredentials`;
  a missing one `400 MissingCredentials`. `AdminNotConfigured` is gone.
- **The admin's routes refuse other accounts:** `403 Forbidden`, before the
  route says whether the record it names exists.

### Added

- **Sign-up** (`POST /auth/register`), `GET /auth/me`, and `GET /auth/config`
  (whether sign-up is open, and Turnstile's site key). Sign-up is limited to 5
  accounts an hour per IP.
- **Cloudflare Turnstile** on sign-up and login, when its keys are set. A token
  passes once, for its own form.
- **"Keep me signed in"**: a login lasts 30 days instead of one.
- **Favorite lists** (`/lists`): the heart is the default "Favorites" list,
  and an account can make others; a media page adds to them. Signing in moves
  the browser's favorites into the account.
- **Blocked tags** (`/account/blocked-tags`): media carrying one leaves the
  account's listings, counts, searches and random picks. Opened directly, it
  asks before showing.
- **Saved searches** (`/saved-searches`), from the navbar's bookmark menu.
- **Account page**: blocked tags, saved searches, a new password, and deleting
  the account.
- **Users page** for admins (`/users`): make or unmake admins, set a password,
  delete an account. There is always one admin left.
- `php artisan gallery:user`: make an account, make it an admin or not, or set
  its password.

### Fixed

- **Rate limits counted per Cloudflare server, not per visitor**, so everyone
  behind one shared a limit: a few wrong passwords could lock out a whole
  region. Laravel now trusts Cloudflare's address ranges, and only those, to say
  who the visitor is.

### Security

- `public/` refuses every dotfile, not only `.user.ini`, in case a `.env` or
  the like is ever put there. (The real `.env` sits beside `public/`, which is
  all Apache serves, and was never reachable.)
- Changing a password signs out the account's other logins.

## [5.0.0] - 2026-11-03

The backend moved from Slim 4 to **Laravel 13**, which changes how the gallery
runs: a new server layout, a queue worker and a scheduler. With the move done,
the API was reworked to use what Laravel offers, so the HTTP contract moves to
**`5.0.0`**: HTTP caching, tag search, queued uploads, real 404s, partial edits
and cursor paging. The move also turned up two old problems, with large uploads
and with Danbooru, fixed here. How the migration was done, and its deliberate
differences from API 4, are in [docs/laravel-migration.md](docs/laravel-migration.md).

### Breaking (for running the gallery)

- **Server layout.** The app's `public/` folder is the DocumentRoot; the code,
  `.env` and the data (`db/`, `media/`, `dupes/`) sit beside it, unserved. The
  flat webroot is gone.
- **A queue worker and a cron entry are required.** `php artisan queue:work`
  (the systemd unit `gallery-queue` on the droplet) runs ingests, Danbooru
  imports and rebuilds; `php artisan schedule:run`, every minute, runs the
  nightly ingest and prunes expired logins.
- **The maintenance scripts are Artisan commands:** `cron.php` →
  `gallery:ingest`, `cron_with_tags.php` → `gallery:ingest --import-tags`,
  `tag_imports.php` → `gallery:import-tags`, `regenerate-*.php` →
  `gallery:regenerate {thumbnails|fingerprints|metadata}`, `dupes.php` →
  `gallery:dupes` (same options).
- **Settings.** A Laravel `.env` (`APP_KEY`, `DB_DATABASE`,
  `QUEUE_CONNECTION=database`, …) with the gallery's keys as before, plus
  `GALLERY_MEDIA_PATH`, `GALLERY_DUPES_PATH`, `SCHEDULE_TIMEZONE` and
  `DANBOORU_USER_ID`.
- **Everyone logs in once more.** Login tokens moved to Sanctum.

### Breaking (for API clients — the contract is `5.0.0`)

- **`GET /tags/display` is a page**, `{ items, total, current_page, total_pages }`,
  taking `page`, `per_page` (default 25), `search` (tag or category name),
  `sort` (`tag_name`, `category_name`, `media_count`), `direction` and `ids`.
  It was every tag at once.
- **Uploads queue their slow work.** `POST /media` answers once the files are
  stored; thumbnails and Danbooru lookups run on the queue. With `fetch_tags`,
  each stored file says `tags_status: "queued"`, or why not as `tags_error`;
  `GET /media/tag-imports?ids=…` reports how each lookup went. The per-file
  `tags_found`, `tags_applied` and `tags_method`, and `total_tags_applied`,
  are gone.
- **A path id names a record or the request is a 404**, with the resource's
  code: `MediaNotFound`, `TagNotFound`, `CategoryNotFound`, `MappingNotFound`,
  `ImplicationNotFound`. A non-numeric id matches no route (`404 NotFound`).
  API 4 answered 400 for a malformed id and 200 or 204 for a missing one.
- **A `PUT` changes only the fields it sends.** API 4 reset the others to their
  defaults.
- `POST /media/{id}/danbooru-tags` no longer returns `all_tags` (every tag in
  the gallery).

### Added

- **Uploads of any number of files.** The upload page sends them in batches of
  at most 20 files and 100 MB, within PHP's limits, which used to drop every
  file past the 20th without a word. A file the server never answered for is
  marked failed, and a file over 100 MB isn't sent.
- **The upload page says when tags are missing:** a warning that stays until
  dismissed when Danbooru tags couldn't be fetched (with the reason), a notice
  for files Danbooru has no post for, and both counts in the upload summary.
- **`DANBOORU_USER_ID`.** Danbooru requests name the account in their
  User-Agent (`GalleryTagImporter/1.0; user #…`), as Danbooru asks of API
  clients; its firewall had started blocking the server's requests without it.
- **`503 DanbooruBlocked`.** Danbooru's firewall refusing the server is
  reported as that, with Cloudflare's Ray ID, instead of as rejected
  credentials. An upload's per-file `tags_error` carries the reason.
- **The nightly ingest actually runs** on a schedule; `cron.php` was never
  scheduled.
- **HTTP caching.** The public reads send an `ETag` with
  `Cache-Control: no-cache, private`, and answer `304 Not Modified` while
  nothing has changed.
- **Tag search:** `GET /tags?search=…` gives the best matches (the name
  itself, then names starting with it, then names containing it), with `limit`
  and `exclude`; `?names=…` and `?ids=…` look tags up. The app searches as you
  type instead of downloading every tag when it starts.
- **Cursor paging:** `GET /media?cursor=…` continues a listing (`tags=…` or
  `untagged=1` keep its filter), and every numbered page gives the
  `next_cursor` to continue from. Infinite scroll uses it, so items added while
  you scroll no longer repeat ones already shown.
- **`PATCH /tags/{id}` and `PATCH /tag-categories/{id}`** for partial edits
  (`PUT` does the same).
- **`GET /media/{id}?include=tags`**: an item with its tags in one request.
- **`DELETE /auth/token`** revokes the token sent, and logging out calls it.
- A failed validation lists every broken field under `errors`, beside the
  first one's `error` and `message`, and the tag form shows the field's message.

### Changed

- **The backend is Laravel 13:** Eloquent models, Form Requests, API Resources,
  Sanctum, the database queue and scheduler, and Pest tests. The 191 requests
  recorded from the Slim app are replayed against it on every test run.
- **Ingest** handles one file at a time: a bad file fails alone instead of
  rolling back the whole batch. The ingest refuses to remove anything when it finds none
  of the gallery's files, which is what a wrong media path looks like.
- **Errors** are always `{ error, message }`: an unknown `/api` path is
  `404 NotFound` (Slim answered an HTML 405), a wrong method
  `405 MethodNotAllowed`. Renaming a tag onto an existing name is
  `400 TagAlreadyExists` and dismissing a pair with an unknown id
  `404 MediaNotFound` (both were 500s). A CORS preflight is `204`.
- The login throttle is 10 attempts per 5 minutes, as the old code intended
  (its cleanup made it about 10 a minute).
- Each OpenAPI operation has a readable `operationId` (`listMedia`,
  `uploadMedia`, …).
- Only server errors (5xx) are logged.
- **The cached lists are cleared by the models**: saving or deleting media,
  tags or categories clears what it affects, from whichever request, job or
  command made the change.
- The Tags page searches, sorts and pages on the server, and the upload page
  reports each queued Danbooru lookup as it finishes.
- Deploys install no dev packages on the server, cache the config and routes,
  and leave the code readable but not writable by other users.
- **The service worker is TypeScript** (`frontend/src/sw/`), type-checked
  against a worker's globals, linted and tested like the app, and built to
  `/sw.js` by a Vite plugin. It shares the thumbnail URLs and the prefetch
  message with the app instead of repeating them.
- **PHP's upload limits are in `public/.user.ini`**, which PHP-FPM reads.
  `.htaccess` keeps them for mod_php inside `<IfModule php_module>`, since
  without mod_php a bare `php_value` makes Apache refuse every request. The
  droplet now runs Apache's event MPM with PHP-FPM: thumbnails Cloudflare
  hasn't cached load about 13 times faster, and API requests no longer wait
  behind them.

### Fixed

- After an upload, the files kept for a retry showed other files' statuses.
- The upload page advertised 500 MB per file; the server accepts 100 MB.
- **Infinite scroll's thumbnails arrived slowly** when they weren't cached.
  The service worker prefetched both sizes of every thumbnail, though a screen
  shows one; downloaded a second copy when the page asked for one still on its
  way; and listed all 2,000 cached thumbnails after storing each download. It
  now prefetches the size the cards use, shares a download between the
  prefetch and the page, and trims its cache once per 100 downloads. The media
  listings no longer pass through it (its rule for them matched only the
  `/api/media?page=…` form the API stopped using in 3.0.0).
- **Deploys took the site down for 10–20 seconds.** The new code was unpacked
  over the live folder, and every request failed until Composer, the migration
  and `optimize` had run: Laravel couldn't write `bootstrap/cache`, and with
  `public/media` gone, thumbnails fell through to Laravel too. One deploy logged
  75 fatal errors. The new code is now built beside the live code and swapped
  in by renames.
- **A deploy's service worker reached browsers up to two hours late.**
  Cloudflare cached `/sw.js` like any `.js` file. It is now sent with
  `Cache-Control: no-cache`, so Cloudflare checks with the server every time.
- The API docs said `POST /tags/{id}/migrate` deletes the source tag; it keeps
  it (`DELETE /tags/{id}/migrate-to/{target_id}` deletes).

### Security

- Login tokens are stored hashed (SHA-256), expire after 24 hours and are
  pruned daily.
- Only `public/` is served. In the old flat webroot, any file put there was
  reachable unless a rule blocked it.
- Every response, the SPA's pages included, carries `X-Frame-Options` and
  `X-Content-Type-Options`.

### Removed

- The Slim app, Phinx, and the hand-built rate limiter, response cache and
  token table they came with.

## [4.0.0] - 2026-08-05

A UI and design-system release. The interface was rebuilt around a token layer
and a real type scale, the interactive primitives moved to
[Reka UI](https://reka-ui.com), and the tag palette was separated from the
semantic (state) palette at the data layer. **Breaking:** the tag category
shortcode is gone, so the HTTP contract moves to `4.0.0`.

### Breaking

- **`category_short` removed from tag categories.** The field existed for one
  feature — typing `a:artist name` so the prefix picked the category — parsed by
  `TagRepository::getOrCreate()`, which had no callers. Every live path that
  creates a tag already set the category explicitly (the Tags page dropdown, or
  the Danbooru importer's category map), so the column, its two unique indexes,
  the API validation and the help text all described something the application
  could not do. `POST`/`PUT /tag-categories` no longer accept or require it, and
  it is gone from the `TagCategory` schema. Migration `20260806000000` drops the
  column; `down()` restores it but re-derives the codes from category initials.
- **Tag category colors moved off the semantic palette.** Categories stored Bulma
  state names — Artist was literally `danger` — which made a tag chip and the
  delete button the same red and tied the tag colors to Bulma. Migration
  `20260805000000` rewrites the stored values (`danger→rose`, `warning→amber`,
  `success→emerald`, `info→sky`, `white→neutral`), preserving how each category
  looks. `VALID_COLORS` is now nine hue names with no state names among them.

### Added

- **One rule for where feedback appears.** Response messages were split between
  the toast system and four ad-hoc Bulma `.notification` blocks that picked
  their colours off the framework palette — and in two cases said the same thing
  twice, so completing an upload or a Danbooru import showed a banner while an
  identical toast slid in beside it. Feedback now has two homes with a clear
  rule: a **toast** reports the outcome of a finished action, and the new
  `AppAlert` states a condition where it applies and stays there while you act
  on it (an import failure keeps its explanation inside the dialog you retry
  from, and the upload page keeps a summary of the last run once the toasts have
  gone — including after a failure, which the banner it replaces never showed,
  because it required an empty file list and failed files stay selected for
  retry). Genuinely duplicated messages collapsed into the toast. The two are
  drawn deliberately unalike so it is obvious which will vanish on its own: a
  toast is an object above the page — neutral surface, drop shadow, severity
  stripe, close button — while an alert is flush in the page, flat and washed
  with the severity colour, with nothing to dismiss. Field validation is
  neither, and sits beside its input.
- **Click-to-zoom on media** — the image on a detail page opens a full-bleed
  lightbox (keyboard and Escape included). Previously the only route to full
  resolution was a text link inside the metadata table.
- **A position indicator for infinite scroll** — a pinned status pill showing
  where you are in the collection (`1,240 of 5,282`), how much has loaded, and a
  way back to the top. Infinite scroll has no pager and scrolls the page header
  out of view, so there was previously nothing on screen telling you where you
  were.
- **Thumbnail prefetch during infinite scroll** — each batch now warms the next
  one, the same way paging already warmed the next page. Prefetch had only ever
  run in paged mode, so every infinite-scroll batch arrived cold.
- **Tooltips on icon-only controls**, replacing `title` — which never appears on
  touch and cannot be reached by keyboard.
- **Design tokens** — `--surface-*`, `--text-*`, `--border*`, a six-step type
  scale, a space scale and radii, with three deliberately separate palettes:
  neutral chrome, semantic state, and tag categories.

### Changed

- **The media grid fills the row.** `repeat(auto-fill, minmax(150px, 200px))`
  sizes track *count* from the maximum, so it always produced rigid 200px columns
  and left the remainder as dead gutter — and exactly one column on a 375px
  phone. Now `minmax(_, 1fr)`: 6 columns instead of 5 at 1280px with no dead
  gutter, and 2 instead of 1 on a phone. Thumbnails stay letterboxed.
- **The navbar no longer does five jobs.** Blur is a toggle that stays put at
  every width; items-per-page and infinite scroll merged into one "view mode"
  select (they were always the same decision); Upload, Duplicates and sign-out
  moved into an account menu, so signing in no longer changes the shape of the
  nav and sign-out is no longer styled as a destructive action. Search is now a
  sibling of the collapsing menu rather than inside it, so it survives on mobile.
- **Control visibility is declared per route** (`meta.showBlur`,
  `meta.showViewMode`) instead of a chain of negations. Duplicates gains the blur
  toggle it always needed; Favorites and the 404 page lose the items-per-page and
  infinite-scroll controls, which did nothing there.
- **Interactive primitives now come from Reka UI** — `Dialog` (and `AlertDialog`
  for irreversible actions), `DropdownMenu`, `Combobox`, `Toast`, `Pagination`,
  `Toolbar`, `Toggle` and `Tooltip`.
- **A type scale is actually applied.** The gallery page previously used exactly
  two font sizes, and page titles varied between views; there is now one
  `PageHeader` and a scale from 11px to 25px.
- **Density**: Bulma's `.section` (48px on every side) and `.footer` (226px tall
  for three lines) are overridden responsively. Chrome above the first thumbnail
  drops from 250px to ~140px on desktop and 302px to ~136px on a phone.
- **The breadcrumb is part of the page header** rather than a separate 48px band,
  and the last crumb is the title instead of being repeated below it.
- **The Tags page is usable on a phone** — the table becomes a list below 768px.
  As a table its rows inflated to 132px because the actions column squeezed to
  56px and stacked its buttons, 242px off the right edge.
- **One overflow menu per tag row** instead of three filled buttons, which at 25
  rows meant 75 saturated controls competing with the content.
- **Media metadata is a definition list**, not a bordered table with no
  `.table-container` — which clipped the date and put the MD5 copy button off
  screen at 375px.
- **The tag help panel is a color legend.** With shortcodes gone, the useful half
  was always "what do these colors mean".
- **Toasts** are rebuilt on Reka UI, keeping the existing store. Severity is a
  stripe drawn from the shared semantic tokens; the old container carried
  fourteen hardcoded colors, including a second grey ramp and Bulma's stock
  palette.
- **`EmptyState` and `StatRow` are components**, so "nothing here" reads the same
  everywhere. Duplicates previously used a plain notification bar for it.
- **The service worker only registers in production** and `CACHE_VERSION` is
  bumped to `v3`, so a deploy can't serve a mix of two builds and a worker no
  longer sits in front of the dev server.
- **Deploys snapshot the database before migrating** (`db/.backups/`, via SQLite
  `VACUUM INTO`, five kept). The rollback snapshot was code-only, so a failed
  migration left rolled-back code against a forward-migrated database. The
  snapshot is deliberately *not* auto-restored — the failure trap fires on any
  non-zero exit, and reverting data could discard uploads from the deploy window.

### Fixed

- **Danbooru import reported every failure as "not found on Danbooru".**
  `apiGet()` collapsed any non-200 response into `null`, so rejected credentials,
  a rate limit and a genuine miss were indistinguishable — a request Danbooru
  refused (a revoked key, or a source address missing from the key's IP
  allowlist, which is what enabling IPv6 on the server causes) returned a 404 for
  every single item, including ones that plainly exist. The tagger now
  records *why* a call failed and the endpoint reports it: `500`
  `DanbooruAuthFailed` for rejected credentials, `429` `DanbooruRateLimited`,
  `503` `DanbooruUnreachable` for a network or TLS failure, and `503`
  `DanbooruUnavailable` for an upstream error, each logged server-side. A real
  miss still returns `404`. These deliberately avoid `502`/`504`: Cloudflare
  replaces gateway-class responses with its own HTML error page, which would
  discard the explanation.
- **`composer lint` failed on 34 files nobody had edited.** The repository had no
  `.gitattributes`, so a Windows checkout with `core.autocrlf=true` wrote CRLF
  while phpcs PSR-12 rejects any file whose line endings are not LF — and
  `git checkout` silently undid whatever Prettier had just normalised. A
  `.gitattributes` now pins `* text=auto eol=lf`, which overrides
  `core.autocrlf` so no per-machine setup is needed, and the working tree was
  converted. `LICENSE`, the one file stored as CRLF in the index itself, is
  normalised too. This also flushed CRLF out of `openapi.json`, where line
  endings from PHP docblocks had been baked into description strings as escaped
  `\r\n`.
- **Toasts raised from inside a modal could not be dismissed.** Reka's Dialog
  sets `pointer-events: none` on `<body>` while it is open, which the toast
  viewport inherited, leaving Dismiss and Undo dead on any toast a dialog
  produced — the toast was drawn on top (`z-index: 64` over the overlay's `60`)
  but nothing in it could be clicked. The viewport now sets `pointer-events:
  none` explicitly, so its mostly-empty rectangle never swallows clicks meant
  for the page, with `pointer-events: auto` on each toast.
- **Infinite scroll stalled on tall viewports.** `IntersectionObserver` only
  reports intersection *changes*; on a window tall enough that a fresh batch
  didn't push the sentinel back out of view, no second callback ever arrived and
  loading stopped with a spinner and nothing left to scroll. Batches now top up
  while the sentinel remains in the trigger zone.
- **Tag search didn't filter.** The combobox bound a `searchTerm` prop that does
  not exist on Reka's `ComboboxRoot`, so typing never reached the filter, and the
  root's own selection surfaced the raw tag id beside the chip.
- **Dropdown surfaces rendered unstyled** — transparent, unbordered,
  `z-index: auto`. Reka renders them through a Teleport, and Vue cannot apply a
  `data-v-*` scope id across that boundary, so every rule in a `<style scoped>`
  block silently failed to match. Portalled surfaces now live in the global sheet.
- **Hover styling on inert navbar cells** — Bulma reuses `.navbar-item` for the
  `<div>` wrappers around form controls, so five non-interactive cells lit up as
  if they were links.
- **The tag search box** used a different background *and typeface* from every
  other input, from a grey ramp and font stack that matched nothing else.
- Dead dependency `@vueform/multiselect` removed — never imported.

### Security

- **squizlabs/php_codesniffer 4.0.1 -> 4.0.4** (CVE-2026-67434, OS command
  injection, high). This one reached production: `scripts/deploy.ps1` runs
  `composer install` *without* `--no-dev` on purpose, because Phinx — the
  migration tool — is a dev dependency, so `require-dev` packages land on the
  host rather than staying on developer machines.
- **nanoid 3.3.17 -> 3.3.18** (GHSA-2v37-7h3g-55p8, high) — a transitive
  dependency of `vite` via `postcss`, build-time only and never bundled into
  what ships. Patched in the lockfile; `vite` and `postcss` are unchanged.
- Remaining dependencies brought current: `phpunit` 13.0.0 -> 13.3.1,
  `phpstan` 2.2.2 -> 2.2.8, `phpstan-phpunit` 2.0.16 -> 2.0.18, `php-cs-fixer`
  3.95.11 -> 3.95.20, `phinx` 0.16.11 -> 0.16.12, `swagger-php` 6.3.0 -> 6.6.0
  (which regenerates `openapi.json` byte-identically, so the contract is
  unchanged), plus `vue` 3.5.41, `vite` 8.2.1, `vitest` 4.1.11, `reka-ui`
  2.10.3, `eslint` 10.8.1, `happy-dom` 20.11.2, `vue-tsc` 3.3.10, `@types/node`
  26.2.0 and `pinia` 3.0.4 -> **4.0.3**.
- **TypeScript stays on 6.0.3.** 7.0.2 is the native port and drops the
  `./lib/tsc` subpath from its package `exports`; `vue-tsc` 3.3.10 — the current
  release — resolves that path directly and dies with
  `ERR_PACKAGE_PATH_NOT_EXPORTED`, so typechecking is impossible on TS 7 until
  `vue-tsc` ships support. Its `>=5.0.0` peer range does not reflect this.

- `roave/security-advisories` refreshed. It is pinned to `dev-latest` and blocks
  installing known-vulnerable packages, but only knows what its last-pulled
  commit knew — the phpcs advisory was published after the pinned commit, which
  is how 4.0.1 got in. Worth refreshing alongside any dependency work.

### Removed

- `TagRepository::getOrCreate()` and `TagCategoryRepository::getByShortcode()`,
  both unreachable.
- `TagShortcodeModal`, replaced by `TagCategoryLegend`.

## [3.3.0] - 2026-07-03

A broad frontend release: infinite-scroll continuity, a consistency and
accessibility pass across the tag-management section, random-media provenance,
and a couple of fixes. No API changes (the HTTP contract remains at `3.0.0`).

### Added

- **Infinite scroll reflects your position in the URL** — as you scroll, the
  address updates to the page whose items are at the top of the viewport, so the
  URL stays honest instead of stuck at `/media/1/40` and the spot is shareable.
- **Toggling infinite scroll preserves your place** — turning it off drops you on
  the page you were viewing (instead of page 1), and turning it on resumes from
  that page rather than the top. The list loads in both directions, so you can
  scroll up to earlier pages or down to later ones from wherever you started.
- **Returning from a media item restores your place** — the gallery view is kept
  alive, so pressing Back from a detail page returns you to the same accumulated
  items and scroll position rather than the top.
- **Random-media provenance** — viewing an item via the Random action now uses a
  clean `/random/media/:id/tags` route (no query string), highlights **Random**
  in the navbar, and shows a **Gallery → Random → Media #ID** breadcrumb; a **New
  Random Media** button replaces Back on those pages to roll another item.
- **Six new category colors** — `blue`, `green`, `yellow`, `sky`, `fuchsia`, and
  `slate` — expanding the palette beyond Bulma's semantic set
  (`danger`/`warning`/`success`/`info`/`white`).
- **Keyboard-operable sortable headers** on the Tags list — the sort controls are
  now real buttons with `aria-sort` state, reachable without a mouse.

### Changed

- The whole tag-management section (Tags, Categories, Import Rules) now uses the
  wider content container (up to 1500px), matching the media and gallery pages,
  and every table scrolls horizontally within its own container on small screens
  instead of breaking the layout.
- On the Tags list, tag names render as category-colored **pills** (as they do on
  the media page) and the category shows as bold text; the default sort is now by
  **media count (most-used first)** instead of symbols-first alphabetical.
- Standardized the button accent palette across the section and **removed all
  outlined "ghost" button styles**, including the navbar login/logout buttons.
- Cross-links (media counts, etc.) are proper router links — keyboard-focusable
  and open-in-new-tab — and icon-only action buttons gained `aria-label`s.
- Removed the redundant **Color column** from the Tag Categories table (the
  category-name pill already conveys the color).
- The blur, infinite-scroll, and items-per-page navbar controls are hidden on
  pages with no browsable media grid — the tag-management section (whose tables
  paginate themselves) and the admin Upload and Duplicates pages.
- Action columns are right-aligned in every tag-section table so the row controls
  sit flush at the table's end.

### Fixed

- Gallery thumbnail **blur no longer cuts off at the tile edges** — blurred
  thumbnails are scaled inside a clipped container so the blur fills each tile
  edge-to-edge with clean rounded corners instead of a faded rectangular border.
- Danbooru Import Rules deletions now use confirmation **modals** instead of the
  browser's native `confirm()` dialog, matching the rest of the app.

## [3.2.0] - 2026-07-03

A redesign of the media detail / tag-management page, plus favorite-state and
overflow-menu polish. Frontend only — no API changes (the HTTP contract remains
at `3.0.0`).

### Added

- **Clickable tags** on the media page — each tag links to that tag's filtered
  gallery listing.
- **Category-grouped tags** — the "Current Tags" list is split into labeled
  sections (Artist / Source / General / Meta …), ordered by category sort order
  and alphabetized within each group, so a long tag list is easier to scan.
- **Copy button** for the MD5 hash.
- **Undo** action support in toast notifications, used when removing a tag.

### Changed

- The media detail page uses a wider content container (up to 1500px) so the
  image and details panel fill large displays instead of stranding empty side
  margins; unchanged on smaller screens.
- Toolbar buttons are solid/purposeful throughout (no outlined "ghost" styles),
  and the less-used, more-destructive **Fetch Tags** and **Delete** actions are
  collapsed into an overflow (⋯) menu; Fetch Tags uses a clearer cloud-download
  icon.
- Removing a tag no longer interrupts with a blocking `confirm()` dialog — it
  removes immediately and offers an **Undo** toast — and the remove (×) target on
  each tag is larger for easier tapping.
- The **Favorite** button now shows an explicit state: a neutral button with an
  outline heart ("Favorite") when unset, and a solid pink button with a filled
  heart ("Favorited") once favorited.
- The add-tags search field has a lighter surface and a visible border so it
  reads as an input rather than blending into the page background.
- Infinite-scroll and items-per-page controls are hidden from the navbar on the
  single-item detail view, where they have no effect (blur toggle stays).
- The media image carries descriptive `alt` text instead of an empty attribute.

### Fixed

- The admin overflow menu rendered as two mismatched background shades; its panel
  and items now share one consistent surface matching the app's modal styling.

## [3.1.0] - 2026-07-03

Frontend UX and accessibility improvements to the media gallery. No API changes
(the HTTP contract remains at `3.0.0`).

### Added

- **Jump-to-page** field built into the pagination bar — type a page number and
  press Enter to go straight there. Out-of-range entries are clamped to the last
  page. Shown only when there are more than 5 pages.
- **Infinite scroll** is now a dedicated navbar toggle (persisted in
  `localStorage`, like the blur toggle) instead of an option hidden inside the
  items-per-page dropdown. Enabling it disables the items-per-page selector.
- Current gallery item count on the admin **Upload** page for reference.

### Changed

- The media grid uses a wider content container on large / high-DPI displays
  (up to 2200px) so it fills the viewport instead of stranding large empty side
  margins; unchanged on smaller screens.
- Gallery thumbnail cards are keyboard-navigable — focusable, with Enter/Space to
  open — and carry descriptive `alt` text / accessible names.
- Favorite (heart) button hit target enlarged to 44×44px, with higher contrast.
- Pagination now meets **WCAG AA**: current-page, disabled Previous/Next, and the
  ellipsis all clear a 4.5:1 contrast ratio.
- Navbar toggles and icon-only controls (blur, infinite scroll, untagged filter)
  gained `aria-label` / `aria-pressed` state.

### Removed

- The redundant "Media — Page X of Y" header row above the gallery (the
  highlighted page number already indicates position) and the always-visible
  total item count from the gallery view.

### Fixed

- Legacy `perPage=0` URLs (the previous infinite-scroll sentinel) now fall back to
  the default page size instead of reaching the API with an invalid value.

## [3.0.0] - 2026-07-01

A rework of the HTTP API into a **hybrid REST-RPC** design with a
machine-readable **OpenAPI 3.1** contract, an interactive **Scalar** docs page,
and **generated TypeScript types** consumed by the frontend. This is a breaking
release: nearly every endpoint's path, verb, and/or status code changed.

### Added

- **OpenAPI 3.1 specification** generated from PHP attributes with
  [`zircote/swagger-php`](https://github.com/zircote/swagger-php) (`composer docs`
  → committed `backend/openapi.json`). Every controller action and structure class
  is annotated; a `bearerAuth` security scheme documents the token model.
- **Interactive API reference** (Scalar) at **`GET /api/docs`**, with the raw
  document at **`GET /api/openapi.json`**.
- **`GET /api/version`** — reports the running application and API versions.
- **Generated TypeScript types** for the frontend via
  [`openapi-typescript`](https://github.com/openapi-ts/openapi-typescript)
  (`npm run gen:types` → `frontend/src/types/api.generated.ts`), surfaced through
  ergonomic aliases in `frontend/src/types/index.ts` and a central
  `frontend/src/api/endpoints.ts` route registry.
- **App version in the footer** (injected at build time from `package.json`),
  linking to the API docs.
- CI freshness gates: the committed `openapi.json` and `api.generated.ts` must be
  regenerated and in sync (`OpenApiSpecTest` + `git diff --exit-code`).

### Changed

- **REST-RPC route surface.** Reads return `200`, creates `201` (with the created
  resource), updates `200` (with the updated resource), and deletes `204`. Routes stay
  clean and path-based (no query strings). Notable moves:
  - Listings keep path-based pagination: **`GET /media/page/{page}[/{per_page}]`**,
    **`/media/untagged/{page}[/{per_page}]`**, **`/media/with-tags/{tag_list}/{page}[/{per_page}]`**
    (now returning the `MediaPage` envelope with proper status codes).
  - Tags are a proper resource: **`POST/PUT/DELETE /tags[/{id}]`** (was `/tags/add`,
    `/tags/edit/{id}`, `/tags/delete`); migrate-then-delete is
    **`DELETE /tags/{id}/migrate-to/{target_id}`**, and **`POST /tags/{id}/migrate`** moves usages.
  - Categories and implications are top-level resources (**`/tag-categories`**,
    **`/tag-implications`**), and media-scoped tagging nests under media
    (**`GET/PATCH/DELETE /media/{id}/tags[/{tag_id}]`**,
    **`POST /media/{id}/danbooru-tags`**).
  - Upload is the media create: **`POST /media`** (multipart). Bulk delete is
    **`POST /media/bulk-delete`**; Danbooru rules split into
    **`/danbooru/category-mappings`** and **`/danbooru/tag-mappings`**.
  - `GET /media/total` (bare int) → **`GET /media/count`** (`{ count }`).
- Controllers were reorganized for cohesion: dedicated `AuthController`,
  `SystemController`, `TagCategoryController`, and `TagImplicationController`; the
  media–tag relationship and bulk delete moved onto `MediaController`.
- The frontend consumes generated types end-to-end; the hand-written `Tag` /
  `TagCategory` / `MediaItem` interfaces in `stores/gallery.ts` were removed.

### Removed

- The action-in-URL endpoints (`/tags/add`, `/tags/edit/{id}`, `/tags/delete`,
  `/tags/migrate`, `/tags/for/media/{id}`, `/tags/media/add|remove`,
  `/tags/danbooru-fetch`, `/tags/categories/*`, `/tags/implications/*`,
  `/danbooru/*-map/*`, `/upload/media`, `/duplicates/media`, `/duplicates/dismiss`)
  and the paginated `/media/page`, `/media/with-tags`, `/media/untagged` routes.
- The hand-written frontend domain interfaces (now generated).

### Security

- The bearer-token model is unchanged: all `GET`s are public; state-changing
  methods require a token, save for a small public allowlist (media tagging and
  the batched `POST /media/by-ids` read), now matched by an exact
  `(method, path pattern)` rule so a public sub-route can't widen a protected one.

## [2.0.2] - 2026-06-30

### Added

- **PHPStan static analysis** at level 8 (`backend/phpstan.neon`, `composer analyse`),
  with the `phpstan-phpunit` extension and a CI gate. The whole backend is clean at level 8.
- **`backend/API.md`** — a full HTTP API endpoint reference (auth model, response
  envelope, rate limiting, and every endpoint grouped by resource).
- Per-action route docblocks (`VERB /path`) on every controller, and `#[\NoDiscard]`
  (PHP 8.5) on critical repository mutators whose return must be checked.

### Changed

- Collapsed the pass-through `Collection` layer into unified repositories
  (`Gallery\Repository\TagRepository`, `TagCategoryRepository`, `DanbooruRulesRepository`);
  `MediaCollection` keeps its real file/thumbnail behavior.
- Controllers no longer hold the DI container (service-locator removed) — collaborators,
  including a now lazily-initialized `DanbooruTagger`, are constructor-injected directly.
- Structure classes (`Media`, `Tag`, `TagCategory`) now use PHP 8.4 **asymmetric
  visibility** (`public private(set)`), dropping the getter boilerplate while keeping
  reads public and writes encapsulated.
- Cache groups are now a `CacheGroup` enum (was bare strings); request parameters go
  through typed `intParam`/`stringParam`/`parsedBody` helpers; the `sanitizeTagName`
  pipeline uses the PHP 8.5 pipe operator.

### Fixed

- `TagRepository::getOrCreate` no longer relies on an implicitly-defined `$name`
  variable on the no-prefix path (surfaced by static analysis; now always initialized).

## [2.0.1] - 2026-06-28

### Added

- Automated test suites: **PHPUnit 13** for the backend (`backend/tests/`, run against an
  in-memory SQLite database) and **Vitest** for the frontend (`frontend/src/__tests__/`,
  happy-dom). Both run in CI with coverage reporting.

### Changed

- Refactored the backend Storage/Collection layers, `RateLimiter`, `DanbooruTagger`, and
  `DuplicateScanner` to **constructor dependency injection**. The PHP-DI container
  (`backend/api/dependencies.php`) now supplies `PDO` and autowires the graph for both the API
  and the CLI scripts, making the data layer unit-testable without touching the live database.

### Fixed

- `AbstractStructure` array construction (e.g. `new Media([...])`) now works — properties are
  assigned via reflection, fixing a latent error when the base class set a subclass's private
  properties.

## [2.0.0] - 2026-06-28

A complete rewrite of Gallery as a **Vue 3 + TypeScript single-page app** on a
**Slim 4 / PHP 8.5** API, replacing the original jQuery + server-rendered version.
The data model was unified, a full tagging system was added, and the project was
reorganized into a clean `backend/` + `frontend/` split. The previous jQuery app
was never formally versioned; this is the first release under semantic versioning.

### Added

- **Vue 3 + TypeScript SPA** frontend (Composition API with `<script setup>`,
  Pinia stores, Vue Router, Vite build, Bulma styling).
- **Unified media model** — images, animated GIFs, and videos live in one `media`
  table and one browsable grid, with both pagination and infinite-scroll modes.
- **Tag system** — categories (with colors, shortcodes, descriptions, sort order)
  and **tag implications** (applying tag A transitively auto-applies its implied tags).
- **Tag search** with include/exclude filters (`+tag` / `-tag`).
- **Danbooru integration** — auto-import tags by MD5 hash with an IQDB
  visual-similarity fallback; database-driven, UI-editable import rules
  (category and tag-name mappings).
- **Duplicate detection** — perceptual hashing pipeline (LSH candidate generation →
  Hamming-distance filter → SSIM verification) with a review/dismiss UI.
- **Favorites**, persisted client-side in `localStorage`.
- **Admin authentication** — shared password exchanged for a 24h bearer token;
  gates uploads and deletes.
- **Multi-file uploads** through the SPA.
- **Media metadata** (width, height, duration, file size) extracted at ingest and
  backfillable for existing rows.
- **Open Graph / Twitter Card** meta-tag injection via the `index.php` front
  controller for link previews.
- **Service worker** — cache-first thumbnails/static assets, network-first media
  lists, plus adjacent-page thumbnail prefetch.
- **Server-side response cache** (file-based, group-invalidated) for hot GET endpoints.
- **Rate limiting** — global per-IP sliding window plus a stricter login bucket.
- **Phinx migrations** as the single source of truth for the schema.
- **PowerShell deploy script** — build → tarball → upload → `composer install` →
  migrate, with an automatic rollback snapshot.
- **PSR-12 linting** (`phpcs`) and a **GitHub Actions CI** pipeline (PHP lint +
  frontend type-check/build).
- Project docs: this `CHANGELOG.md`, `CONTRIBUTING.md`, and an expanded `AGENTS.md`.

### Changed

- **Repository layout** reorganized into `backend/` (PHP app) and `frontend/`
  (Vue/Vite), with a dev-only `scripts/` for deployment. The droplet webroot stays
  flat; the deploy flattens `backend/` + `frontend/dist` onto it.
- **Backend architecture** is now layered: `Controller → Collection → Storage →
  DatabaseConnection (PDO)`, with all SQL confined to the Storage layer.
- Upgraded to **PHP 8.5**, **Slim 4** with the PHP-DI bridge, and **SQLite in WAL mode**.
- `composer.lock` and `frontend/package-lock.json` are now committed for
  reproducible installs.

### Removed

- The legacy **jQuery frontend** (`index.html`, `js/gallery.js`, `css/`).
- The split **Image/Video** classes (`ImageCollection`, `VideoCollection`,
  `ImageStorage`, `VideoStorage`, `Image`, `Video`), unified into `Media*`.
- The `php-ffmpeg/php-ffmpeg` dependency — thumbnails now shell out to `ffmpeg` directly.

### Security

- All state-changing API routes require a bearer token, except a deliberate public
  allowlist (anyone may add/remove tags; `POST /media/by-ids` is a batched read).
- Login is refused entirely when `GALLERY_ADMIN_PASSWORD` is unset (the insecure
  `changeme` default can never grant access); the password is compared with
  `hash_equals()` and login attempts are throttled per IP.
- CSRF protection via `Origin`/`Referer` allowlist checks on state-changing requests.
- `.htaccess` blocks direct web access to `.env`, the SQLite database, logs, cache,
  `vendor/`, and raw source.

## Pre-2.0.0 — Legacy (unversioned)

Before the 2.0.0 rewrite the gallery ran for roughly three years (first commit
**2023-01-02**) as a **jQuery + server-rendered PHP** app. It was never tagged
under semantic versioning; this section is a retrospective summary reconstructed
from the git history, not a formal release.

- **Frontend:** static `index.html` + `js/gallery.js` (jQuery), Bulma styling with
  a custom extended color palette, FontAwesome icons (CDN), and a Lightbox for
  full-size viewing. Async `fetch`/AJAX calls to the PHP API drove the grid.
- **Backend:** PHP with **separate Image and Video** class hierarchies
  (`Image`/`Video`, `ImageCollection`/`VideoCollection`, `ImageStorage`/`VideoStorage`)
  over SQLite; thumbnails were generated via `php-ffmpeg/php-ffmpeg`. The API was
  migrated onto **Slim** partway through this era and PSR-4 autoloading was adopted.
- **Features:** a basic browsable image/video gallery, an initial **tagging system**
  with a dedicated tag page, sorting by tag name, a sticky footer with copyright/
  disclaimer, and a **cron** ingest script for importing media (including videos).

[7.0.1]: https://github.com/jwill89/simple-image-gallery/compare/v7.0.0...v7.0.1
[7.0.0]: https://github.com/jwill89/simple-image-gallery/compare/v6.0.0...v7.0.0
[6.0.0]: https://github.com/jwill89/simple-image-gallery/compare/v5.0.0...v6.0.0
[5.0.0]: https://github.com/jwill89/simple-image-gallery/compare/v4.0.0...v5.0.0
[4.0.0]: https://github.com/jwill89/simple-image-gallery/compare/v3.3.0...v4.0.0
[3.3.0]: https://github.com/jwill89/simple-image-gallery/compare/v3.2.0...v3.3.0
[3.2.0]: https://github.com/jwill89/simple-image-gallery/compare/v3.1.0...v3.2.0
[3.1.0]: https://github.com/jwill89/simple-image-gallery/compare/v3.0.0...v3.1.0
[3.0.0]: https://github.com/jwill89/simple-image-gallery/compare/v2.0.2...v3.0.0
[2.0.2]: https://github.com/jwill89/simple-image-gallery/compare/v2.0.1...v2.0.2
[2.0.1]: https://github.com/jwill89/simple-image-gallery/compare/v2.0.0...v2.0.1
[2.0.0]: https://github.com/jwill89/simple-image-gallery/releases/tag/v2.0.0
