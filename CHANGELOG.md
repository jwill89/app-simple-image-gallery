# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- `task deploy` ships the newest release tagged on main, built in a checkout of its own, so a commit made after the release no longer stops it and nothing uncommitted reaches the site.
- Deploying a release the droplet already runs does nothing; `task deploy -- -Force` deploys it again. `-SkipBuild` goes with `-Preview` alone.

## [7.1.0] - 2026-11-07

### Added

- A daily database backup on the droplet: a timer saves a compressed, integrity-checked copy of the database every day and keeps 28 days.
- The deploy pulls a copy of the live database to the local one, keeping the three previous local copies; `-NoDbPull` skips it.

### Changed

- Icons ship with the app instead of loading from Font Awesome's CDN on every page view, and the `FONTAWESOME_KIT_ID` setting is gone.
- Every database backup on the droplet is compressed. The snapshot taken before a migration is integrity-checked and gzipped, five are kept, and a failed migration prints the command that restores it.
- In the dev server, reloading a gallery page such as `/media/1/40` loads the app, since only the media files are proxied; `GALLERY_BACKEND_URL` points the proxy at another backend.
- The first page appears without fading in. Pages still fade from one to the next.

### Fixed

- Every page has a `main` landmark around its content, so a screen reader can jump to it.
- The search box's icon-only buttons are named "Search" and "Clear search".
- The sign-in and sign-up pages each have a level-one heading, which looks as before.
- Muted text is darker, reaching 4.5:1 contrast on every light surface.
- A link inside a sentence is underlined, so it doesn't depend on colour alone.

## [7.0.1] - 2026-11-06

### Changed

- Adopted the shared lint, test, CI and deploy standards.
- The deploy sends one archive, which the host checks by SHA-256 and file count before anything changes. After the swap it fetches `/api/media/count` and `/` from outside and puts the previous code back if either fails.

## [7.0.0] - 2026-11-03

### Added

- Passkeys: the Account page adds, renames and removes them (the password first), and the login page signs in with one. A passkey sign-in skips Turnstile and counts against the login limit.
- API: `POST /auth/passkey/options` and `POST /auth/passkey` to sign in; `GET|POST /account/passkeys`, `POST /account/passkeys/options` and `PATCH|DELETE /account/passkeys/{passkey_id}`.

### Changed

- Breaking: a browser signs in with a session cookie (HttpOnly, `SameSite=Lax`) that the page's scripts can't read, instead of a token kept in browser storage. Everyone signs in again.
- Breaking: the HTTP contract moves to 7.0.0, with no bearer tokens. `POST /auth/login` and `POST /auth/register` answer `{ user }` and set the cookie, `GET /auth/session` replaces `GET /auth/me`, and `DELETE /auth/session` replaces `DELETE /auth/token`.
- Breaking: a write the browser doesn't mark `Sec-Fetch-Site: same-origin` is `403 ForbiddenOrigin`.
- Sessions are files, set by `SESSION_DRIVER`, `SESSION_LIFETIME` and `SESSION_EXPIRE_ON_CLOSE`; the cookie is Secure whenever `APP_URL` is https. Passkeys belong to `APP_URL`'s host, so it must be the address people use.
- "Keep me signed in" is a 30-day remember cookie; without it, the browser is signed out when it closes.

### Security

- No script on the page can read the login. The token had sat in browser storage, readable by every script on the page, and worked from any machine for up to 30 days.
- Signing in starts a new session id, so one planted beforehand signs no one in.
- A new password replaces the account's remember cookies and ends its other sessions at their next request.

## [6.0.0] - 2026-11-03

### Added

- Accounts: anyone can sign up with a username and password, behind Cloudflare Turnstile when its keys are set, limited to 5 accounts an hour per IP.
- "Keep me signed in": a login lasts 30 days instead of one.
- Favorite lists: the heart is the default "Favorites" list, an account can make others, and a media page adds to them. Signing in moves the browser's favorites into the account.
- Blocked tags: media carrying one leaves the account's listings, counts, searches and random picks.
- Saved searches, from the navbar's bookmark menu.
- An Account page for blocked tags, saved searches, a new password and deleting the account.
- A Users page for admins: make or unmake admins, set a password, delete an account. There is always one admin left.
- A `gallery:user` command makes accounts and admins and sets passwords.
- API: `POST /auth/register`, `GET /auth/me`, `GET /auth/config`, `/lists`, `/account/blocked-tags`, `/saved-searches` and `/users`.

### Changed

- Breaking: an admin is an account with the admin role, replacing the shared admin password (`GALLERY_ADMIN_PASSWORD` is gone). Everyone signs in again.
- Breaking: the HTTP contract moves to 6.0.0. `POST /auth/login` takes `{ username, password, remember? }` and answers `401 InvalidCredentials` or `400 MissingCredentials`, and the admin's routes refuse other accounts with `403 Forbidden`.
- New settings: `GALLERY_ALLOW_SIGNUP` (on unless `false`) and `CLOUDFLARE_TURNSTILE_SITEKEY` / `CLOUDFLARE_TURNSTILE_SECRET` (no secret, no bot check).

### Fixed

- Rate limits counted per Cloudflare server, not per visitor, so everyone behind one shared a limit and a few wrong passwords could lock out a whole region.

### Security

- `public/` refuses every dotfile.
- Changing a password signs out the account's other logins.

## [5.0.0] - 2026-11-03

### Added

- Uploads of any number of files: the upload page sends them in batches of at most 20 files and 100 MB, where PHP had silently dropped every file past the 20th. A file the server never answered for is marked failed, and a file over 100 MB isn't sent.
- The upload page says when tags are missing: a warning that stays until dismissed when Danbooru tags couldn't be fetched (with the reason), a notice for files Danbooru has no post for, and both counts in the upload summary.
- `DANBOORU_USER_ID`: Danbooru requests name the account in their User-Agent, as Danbooru asks of API clients; its firewall had started blocking the server's requests without it.
- `503 DanbooruBlocked`: Danbooru's firewall refusing the server is reported as that, with Cloudflare's Ray ID, instead of as rejected credentials.
- The nightly ingest runs on a schedule.
- HTTP caching: the public reads send an `ETag` and answer `304 Not Modified` while nothing has changed.
- Tag search: `GET /tags?search=…` gives the best matches (the name itself, then names starting with it, then names containing it); `?names=…` and `?ids=…` look tags up. The app searches as you type instead of downloading every tag at start.
- Cursor paging: `GET /media?cursor=…` continues a listing, and every numbered page gives the `next_cursor` to continue from. Infinite scroll uses it, so items added while you scroll no longer repeat ones already shown.
- `PATCH /tags/{id}` and `PATCH /tag-categories/{id}` for partial edits.
- `GET /media/{id}?include=tags` returns an item with its tags in one request.
- `DELETE /auth/token` revokes the token sent, and logging out calls it.
- A failed validation lists every broken field under `errors`, and the tag form shows the field's message.

### Changed

- Breaking: the backend is Laravel 13, replacing Slim 4; `public/` is the DocumentRoot, with the code, `.env` and data beside it. It needs a queue worker and a per-minute scheduler, and the maintenance scripts are `gallery:*` Artisan commands (`ingest`, `import-tags`, `regenerate`, `dupes`).
- Breaking: settings move to a Laravel `.env`, with the new `GALLERY_MEDIA_PATH`, `GALLERY_DUPES_PATH` and `SCHEDULE_TIMEZONE`. Everyone logs in once more.
- Breaking: the HTTP contract moves to 5.0.0. `GET /tags/display` is a page, `{ items, total, current_page, total_pages }`, taking `page`, `per_page`, `search`, `sort`, `direction` and `ids`.
- Breaking: `POST /media` answers once files are stored and queues thumbnails and Danbooru lookups. With `fetch_tags`, each file reports `tags_status` or `tags_error`, `GET /media/tag-imports?ids=…` reports each lookup, and `tags_found`, `tags_applied`, `tags_method` and `total_tags_applied` are gone.
- Breaking: a path id names a record or the request is a 404 with the resource's code (`MediaNotFound`, `TagNotFound` and so on), and a non-numeric id is `404 NotFound`. A `PUT` changes only the fields it sends, and `POST /media/{id}/danbooru-tags` no longer returns `all_tags`.
- Errors are always `{ error, message }`: an unknown `/api` path is `404 NotFound`, a wrong method `405 MethodNotAllowed`, a CORS preflight `204`. Renaming a tag onto an existing name is `400 TagAlreadyExists`, and dismissing a pair with an unknown id `404 MediaNotFound` (both were 500s).
- The ingest handles one file at a time, so a bad file fails alone, and it refuses to remove anything when it finds none of the gallery's files, which is what a wrong media path looks like.
- The login throttle is 10 attempts per 5 minutes (it had been about 10 a minute).
- The Tags page searches, sorts and pages on the server, and the upload page reports each queued Danbooru lookup as it finishes.
- Only server errors (5xx) are logged. Deploys install no dev packages on the server.
- The droplet runs Apache's event MPM with PHP-FPM: thumbnails Cloudflare hasn't cached load about 13 times faster, and API requests no longer wait behind them.

### Fixed

- After an upload, the files kept for a retry showed other files' statuses.
- The upload page advertised 500 MB per file; the server accepts 100 MB.
- Infinite scroll's thumbnails arrived slowly when they weren't cached. The service worker prefetches only the size the cards use, shares a download between the prefetch and the page, and no longer slows scrolling by listing its whole cache after each download.
- Deploys took the site down for 10–20 seconds, with 75 fatal errors in one deploy. The new code is built beside the live code and swapped in by renames.
- A deploy's service worker reached browsers up to two hours late, because Cloudflare cached `/sw.js` like any `.js` file. It is sent with `Cache-Control: no-cache`.
- The API docs said `POST /tags/{id}/migrate` deletes the source tag; it keeps it (`DELETE /tags/{id}/migrate-to/{target_id}` deletes).

### Security

- Login tokens are stored hashed, expire after 24 hours and are pruned daily.
- Only `public/` is served; a file put in the old flat webroot was reachable unless a rule blocked it.
- Every response, the SPA's pages included, carries `X-Frame-Options` and `X-Content-Type-Options`.

## [4.0.0] - 2026-08-05

### Added

- Feedback has two homes: a toast reports the outcome of a finished action, and an alert states a condition and stays in place while you act on it. An import failure keeps its explanation in the dialog you retry from, and the upload page keeps a summary of the last run.
- Click-to-zoom on media: the image on a detail page opens a full-bleed lightbox, with keyboard and Escape support.
- A position indicator for infinite scroll: a pinned pill showing where you are in the collection (`1,240 of 5,282`), how much has loaded, and a way back to the top.
- Thumbnail prefetch during infinite scroll: each batch warms the next one, as paging already did.
- Tooltips on icon-only controls, replacing `title`, which never appears on touch and can't be reached by keyboard.

### Changed

- Breaking: `category_short` is removed from tag categories, along with the `a:artist name` shortcut it served; the HTTP contract moves to 4.0.0. `POST` and `PUT /tag-categories` no longer accept it.
- Breaking: tag category colors are hue names (rose, amber, emerald, sky, neutral and so on) rather than Bulma's state names, so a tag chip and the delete button are no longer the same red. Each category keeps its look.
- The media grid fills the row: 6 columns instead of 5 at 1280px, and 2 instead of 1 on a phone, with no dead gutter.
- The navbar does less: blur is a toggle at every width, items per page and infinite scroll are one "view mode" select, and Upload, Duplicates and sign-out are in an account menu. Search survives on mobile.
- Each page declares which navbar controls it supports: Duplicates gains the blur toggle, and Favorites and the 404 page lose the items-per-page and infinite-scroll controls, which did nothing there.
- Dialogs, menus, tag search, toasts, pagination and tooltips are built on Reka UI, with focus trapping and Escape handling.
- Page titles share one header and a type scale from 11px to 25px. The spacing above the first thumbnail drops from 250px to about 140px on desktop and from 302px to about 136px on a phone.
- The breadcrumb is part of the page header, and the last crumb is the title.
- The Tags page is usable on a phone: below 768px the table becomes a list. Each tag row has one overflow menu instead of three buttons.
- Media metadata is a definition list, so the date isn't clipped and the MD5 copy button stays on screen at 375px.
- The tag help panel is a color legend.
- Toasts show severity as a stripe in the shared semantic colours.
- Deploys snapshot the database before migrating (five kept).

### Fixed

- A Danbooru import reported every failure as "not found". It now says why (`500 DanbooruAuthFailed`, `429 DanbooruRateLimited`, `503 DanbooruUnreachable` or `503 DanbooruUnavailable`), and a real miss is still `404`.
- Toasts raised from inside a dialog could not be dismissed.
- Infinite scroll stalled on tall viewports, ending in a spinner with nothing left to scroll.
- Tag search didn't filter.
- Dropdown surfaces rendered unstyled: transparent, unbordered and behind other content.
- Five non-interactive navbar cells lit up on hover as if they were links.
- The tag search box had a different background and typeface from every other input.

### Security

- Fixed a command-injection flaw (CVE-2026-67434) in a PHP lint tool that had been installed on the server, and a build-time-only advisory (GHSA-2v37-7h3g-55p8).

## [3.3.0] - 2026-07-03

### Added

- Infinite scroll reflects your position in the URL: the address updates to the page whose items are at the top of the viewport, so the spot is shareable.
- Toggling infinite scroll keeps your place: turning it off drops you on the page you were viewing, and turning it on resumes from that page. You can scroll up to earlier pages or down to later ones from wherever you started.
- Returning from a media item restores your place: Back from a detail page returns you to the same items and scroll position.
- Random media has a clean `/random/media/:id/tags` route, highlights Random in the navbar, shows a Gallery → Random → Media #ID breadcrumb, and offers a New Random Media button in place of Back.
- Six new category colors: `blue`, `green`, `yellow`, `sky`, `fuchsia` and `slate`.
- The sortable headers on the Tags list are buttons with `aria-sort` state, reachable without a mouse.

### Changed

- The tag-management section (Tags, Categories, Import Rules) uses the wider content container (up to 1500px), and every table scrolls horizontally within its own container on small screens.
- On the Tags list, tag names are category-colored pills and the category is bold text; the default sort is media count, most-used first.
- Buttons use one accent palette, with no outlined "ghost" styles, including the navbar login and logout buttons.
- Cross-links such as media counts are router links, keyboard-focusable and openable in a new tab, and icon-only action buttons have `aria-label`s.
- The Tag Categories table drops its Color column, since the category-name pill shows the color.
- The blur, infinite-scroll and items-per-page navbar controls are hidden on pages with no browsable grid: the tag-management section, Upload and Duplicates.
- Action columns are right-aligned in every tag-section table.

### Fixed

- Blurred thumbnails no longer cut off at the tile edges.
- Deleting a Danbooru import rule asks in a dialog instead of the browser's native `confirm()`.

## [3.2.0] - 2026-07-03

### Added

- Tags on the media page link to that tag's filtered gallery listing.
- The "Current Tags" list is grouped into labeled sections (Artist, Source, General, Meta and so on), ordered by category sort order and alphabetized within each.
- A copy button for the MD5 hash.
- Toasts can carry an Undo action, used when removing a tag.

### Changed

- The media detail page uses a wider content container (up to 1500px) so the image and details panel fill large displays.
- Toolbar buttons are solid throughout, and the less-used, more-destructive Fetch Tags and Delete actions are in an overflow (⋯) menu. Fetch Tags has a cloud-download icon.
- Removing a tag no longer asks for confirmation: it removes immediately and offers an Undo toast. The remove (×) target on each tag is larger.
- The Favorite button shows its state: a neutral outline heart ("Favorite") when unset, and a solid pink button with a filled heart ("Favorited") once favorited.
- The add-tags search field has a lighter surface and a visible border.
- The infinite-scroll and items-per-page controls are hidden on the single-item detail view, where they have no effect.
- The media image carries descriptive `alt` text.

### Fixed

- The admin overflow menu rendered as two mismatched background shades; its panel and items share one surface.

## [3.1.0] - 2026-07-03

### Added

- A jump-to-page field in the pagination bar: type a page number and press Enter, and an out-of-range entry goes to the last page. It shows only with more than 5 pages.
- Infinite scroll is a navbar toggle, remembered like the blur toggle, instead of an option inside the items-per-page dropdown. Enabling it disables the items-per-page selector.
- The Upload page shows the gallery's current item count.

### Changed

- The media grid uses a wider content container on large displays (up to 2200px).
- Gallery thumbnail cards are keyboard-navigable (focusable, Enter or Space to open) and carry descriptive `alt` text.
- The Favorite (heart) button's hit target is 44×44px, with higher contrast.
- Pagination meets WCAG AA: the current page, disabled Previous and Next, and the ellipsis all clear 4.5:1 contrast.
- Navbar toggles and icon-only controls (blur, infinite scroll, untagged filter) have `aria-label` and `aria-pressed` state.

### Removed

- The "Media — Page X of Y" header row above the gallery and the always-visible total item count on the gallery view.

### Fixed

- `perPage=0` URLs fall back to the default page size instead of reaching the API with an invalid value.

## [3.0.0] - 2026-07-01

### Added

- An OpenAPI 3.1 specification at `GET /api/openapi.json` and an interactive API reference (Scalar) at `GET /api/docs`.
- `GET /api/version` reports the running application and API versions.
- The footer shows the app version and links to the API docs.

### Changed

- Breaking: the HTTP API is a hybrid REST-RPC design. Reads return `200`, creates `201` with the created resource, updates `200` with the updated resource, and deletes `204`. Nearly every endpoint's path, verb or status changed:
  - Listings keep path-based pagination: `GET /media/page/{page}[/{per_page}]`, `/media/untagged/{page}[/{per_page}]` and `/media/with-tags/{tag_list}/{page}[/{per_page}]`.
  - Tags are a resource: `POST|PUT|DELETE /tags[/{id}]`. Migrate-then-delete is `DELETE /tags/{id}/migrate-to/{target_id}`, and `POST /tags/{id}/migrate` moves usages.
  - Categories and implications are `/tag-categories` and `/tag-implications`; media tagging nests under media (`GET|PATCH|DELETE /media/{id}/tags[/{tag_id}]`, `POST /media/{id}/danbooru-tags`).
  - Upload is `POST /media` (multipart) and bulk delete is `POST /media/bulk-delete`. Danbooru rules are `/danbooru/category-mappings` and `/danbooru/tag-mappings`.
  - `GET /media/total` (a bare integer) is `GET /media/count` (`{ count }`).

### Removed

- The action-in-URL endpoints (`/tags/add`, `/tags/edit/{id}`, `/tags/delete`, `/upload/media`, `/duplicates/dismiss` and the rest) and the unpaginated `/media/page`, `/media/with-tags` and `/media/untagged` routes.

### Security

- A public write is matched by its exact method and path, so a public sub-route can't widen a protected one.

## [2.0.2] - 2026-06-30

No user-visible changes.

## [2.0.1] - 2026-06-28

No user-visible changes.

## [2.0.0] - 2026-06-28

A complete rewrite as a Vue 3 single-page app on a PHP API, replacing the original jQuery and server-rendered version. This is the first release under semantic versioning.

### Added

- A single-page app with pagination and infinite scroll.
- A unified media model: images, animated GIFs and videos in one browsable grid.
- Tags with categories (colors, descriptions, sort order) and tag implications: applying a tag also applies the tags it implies.
- Tag search with include and exclude filters (`+tag` / `-tag`).
- Danbooru integration: tags imported by MD5 hash, with an IQDB visual-similarity fallback, and import rules (category and tag-name mappings) editable in the UI.
- Duplicate detection by perceptual hashing, with a review and dismiss page.
- Favorites, kept in the browser's `localStorage`.
- Admin sign-in with a shared password, exchanged for a 24-hour bearer token; it gates uploads and deletes.
- Multi-file uploads.
- Media width, height, duration and file size, read at ingest and backfillable for existing items.
- Open Graph and Twitter Card tags for link previews.
- A service worker that caches thumbnails and static assets and prefetches the next page's thumbnails.
- Server-side caching of hot reads.
- Rate limiting: a per-IP limit on all requests and a stricter one on login.
- A PowerShell deploy script that builds, uploads, installs and migrates, with an automatic rollback snapshot.

### Removed

- The original jQuery frontend.

### Security

- State-changing API routes need a bearer token, except a deliberate public allowlist: anyone may add and remove tags, and `POST /media/by-ids` is a batched read.
- Login is refused entirely while `GALLERY_ADMIN_PASSWORD` is unset, and attempts are throttled per IP.
- State-changing requests are checked against an `Origin` / `Referer` allowlist.
- Direct web access to `.env`, the SQLite database, logs, cache, `vendor/` and raw source is blocked.

## Pre-2.0.0

Before 2.0.0 the gallery was a jQuery and server-rendered PHP app, never versioned. It browsed images and videos, had a first tagging system with a tag page and sorting by tag name, and imported media with a cron script.

[7.1.0]: https://github.com/jwill89/simple-image-gallery/compare/v7.0.1...v7.1.0
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
