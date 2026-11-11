# shared-tooling rollout

The passages of shared-tooling's cross-project plan (`PLAN.md` at v2.6.0) that name this project, moved here word for word on 2026-11-10 when shared-tooling became project-agnostic. The portfolio-wide rest is in `PLAN.md` beside the project folders.

## Decisions so far

The standardization work starts from these, so it doesn't reopen them. All were decided on November 5, 2026.

- Stay on Vue 3; keep Laravel for the Gallery, Python for Conlang Translator and React for Family Card Game; no Rust.
- No hard wrap in Markdown (MD013 off). Markdown sets no line length, and the Gallery's 100 columns came from no standard, so its override goes.

Decided later the same day, while seeding shared-tooling:

- A failed start restores the database snapshot only where a project opts in: Kindred Nights, Home Library and Senpan do; the Gallery doesn't, since uploads made during the deploy would be lost.
- Database snapshots use `sqlite3 .backup` and keep 3; the Gallery keeps 5. (The Gallery kept its own `VACUUM INTO` snapshot until shared-tooling 2.0.0 moved it onto the module's `Backup-RemoteDatabase`, 2026-11-07.)

Decided 2026-11-06, after the Gallery's pilot:

- Stylelint moves to 18 now, in shared-tooling's `lint/web`, with its two configs (`stylelint-config-standard` 41, `stylelint-config-recommended` 19), not waiting on `stylelint-config-recommended-scss`, which covers only TarotGen.io's one `bulma.scss`. It ships in one major release, v2.0.0, with the site-snapshot function and the Gallery's and TarotGen.io's move onto it, so each project updates once. A major of a tool `lint/web` owns always moves here first; each project's Dependabot ignores those majors.
- TarotGen.io stays on PHP and Slim, reversing the port to Go: Go would make no page measurably faster, Slim 4 still ships security releases, and it's a PHP example worth keeping beside the Gallery's Laravel (section 3). If its plugin's share relay returns over WebSockets, that is a small Go service beside the PHP app.
- Every backup kept on the droplet is compressed: the daily copies and the deploys' pre-deploy snapshots, as gzip, each checked (`PRAGMA integrity_check`, then `gzip -t`) before it takes its place; every restore decompresses. The module does it from v1.3.0. The two copies outside the module followed on 2026-11-07: the Gallery's deploy snapshots moved onto the module (v2.0.0), and Kindred's `kindred backup` compresses its own.
- TarotGen.io's schema changes move from untracked one-off scripts to Phinx migrations its deploy runs, starting from a baseline of today's schema. It is the more public, heavily used site, where the Gallery stays on Laravel's own migrations.

Decided 2026-11-07, where the projects' own docs disagreed (55 questions, now rules in `standards/`):

- **Code, tests and releases:** Go packages are split by domain, with no package-level globals, config included. Vue templates have no static `style=""`. BEM class names are for reusable components only. Vitest specs live in `src/__tests__/` as `*.spec.ts`, with the config in `vite.config.ts`. A repo carries one app version, and CI tags each release; a hand-made tag may be lightweight. A commit has a subject and, when the diff doesn't say why, a body. The CHANGELOG lists what users notice; git history keeps the rest. An agent may deploy when asked, asking before it connects. `AGENTS.md` is a repo's one deep guide. Font Awesome comes from the free packages where they cover a site's icons, otherwise from the Pro kit package the owner assigns the site; several sites may share a kit (revised twice the same day: Fantasy Dossier, the Gallery and Home Library now share one). A kit holds only the styles its sites import from, and every icon is a `<font-awesome-icon>`, never an `<i class="fa-…">` element or a kit script.
- **Interface:** layout with Tailwind or with a stylesheet of object classes is each project's choice. Tints use `color-mix()`; CSS never names `black` or `white`. Each animation honours reduced motion itself. Touch targets are at least 44 by 44 px. A new record is made in a dialog. A service worker never caches an API answer; it may cache content-hashed files and thumbnails, and only an installable app precaches its shell (revised the same day, for the Gallery's thumbnails and Senpan's installable app). A list's state lives in the URL, with identity in the path. A dropped live feed shows a banner. A wide table may scroll inside its panel.

## Project by project

Every project stays on its current stack. The Gallery keeps Laravel and TarotGen.io keeps Slim, and both drop Bulma.

| Project | Stack now | Verdict |
| --- | --- | --- |
| Simple Image Gallery | Laravel 13 + Vue / Bulma / Reka UI | Keep Laravel, drop Bulma |

## Where the real gains are

### 1. Stay on Vue; Vapor is a small gain, later

Switching frameworks wouldn't buy much:

- **Svelte or Solid** are faster in benchmarks. But the Gallery's own measurements show the trip through Cloudflare dominates request time, so users wouldn't notice.

### 3. Keep TarotGen.io on PHP

November 5's plan moved its backend from Slim to Go, because Slim's community is small and TarotGen.io is built like Kindred Nights. A closer look on November 6 reversed that. Being built like another project is no reason to rewrite it, and the benefits didn't hold up:

- **No memory saved.** PHP-FPM stays as long as the Gallery and Grav need it.
- **It's the portfolio's PHP-without-a-framework example**, beside the Gallery's Laravel: hand-written repositories, PHP-DI, passkeys, PKCE sign-in for the plugin, CSP nonces, PHPStan and 95% line coverage. Go is already the backend in most of the other projects.

The one place PHP is the wrong tool is the plugin's share relay, which sent a reading straight to another player's plugin. It was removed on 2026-08-19, to come back over WebSockets rather than polling. PHP-FPM can't hold a WebSocket, and an SSE stream ties up one of the 12 workers TarotGen.io shares with the Gallery and Grav. If the relay returns, it's a small Go service beside the PHP app:

## Real-time: WebSockets, streams or REST

| Project | Today | Best fit | Why |
| --- | --- | --- | --- |
| Simple Image Gallery | REST | Keep | No live data; Laravel WebSockets need Reverb, another always-on process |

## Shared packages

The copies have already drifted:

- `@jwill89/eslint-config` is installed in 3 of the 9 Vue front ends (Gallery, TarotGen.io, Fantasy Dossier).

| Where | Purpose | Holds | Used by |
| --- | --- | --- | --- |
| shared-tooling `lint/php` | Lint PHP | phpstan base, php-cs-fixer ruleset, Rector set | Gallery, Fantasy Dossier, résumé site, TarotGen.io |

The VS Code markdownlint extension reads the same config files, so the IDE warnings drop with it. Turn on its fix-on-save (`source.fixAll.markdownlint` in `editor.codeActionsOnSave`). The Gallery's 100-column rule goes too: Markdown sets no line length, and 100 came from no standard.

## Testing standards

| Stack | Today | Gap | shared-tooling adds |
| --- | --- | --- | --- |
| PHP | PHPUnit in TarotGen.io, Pest in the Gallery; 154 test files | A floor only in TarotGen.io | `test/php`: a base `phpunit.xml` (Pest reads it too) and TarotGen.io's floor script, made general |

No test relies on a happy-dom-only API, so the switch is one config line per project plus a test run. The Gallery, Home Library, TarotGen.io and Kindred's markdown test move over; Senpan already uses jsdom. Tests that touch no DOM, like Fantasy Dossier's, stay on Vitest's faster `node` environment.

## Rust

Where it could fit, if a problem shows up:

- **The Gallery's duplicate scan** is the heaviest job on the droplet. If it becomes a bottleneck, a small compiled helper would beat PHP, but a Go one would do nearly as well and match the rest of your code.

## Project gaps

Most gaps are missing CI, Dependabot and post-deploy checks. The riskiest were the three SQLite apps with no scheduled backup, the Gallery, Senpan and Marxbot, until their 2026-11-06 changes: each deploy now installs a daily backup. No secrets are committed: the two tracked `.npmrc` files hold only the registry address, and every deploy config and `.env` file is ignored.

| Project | Missing | Note |
| --- | --- | --- |
| Simple Image Gallery | None once its 2026-11-06 changes land | The second pilot |

## Where to start

<!-- markdownlint-disable MD029 -->

1. **Protect money and data first.**
   - Scheduled database backups for Senpan, the Gallery and Marxbot: they become a shared piece, from Kindred's backup timer, and land with each project's rollout in step 6, the Gallery's first (decided 2026-11-06).

<!-- -->

3. **Gather the standards into `standards/` in this repo** (github.com/jwill89/shared-tooling, public). Claude Code sorts each rule as shared, project-only or conflicting and brings you the conflicts. It comes after the rollouts of Senpan, Marxbot and TarotGen.io (decided 2026-11-06), which take their CLAUDE.md pointer then.
   - Where a decision changes how an existing project works, the change is that project's own follow-up:
     - **Simple Image Gallery**: RFC 9457 errors, 422 (no longer overriding Laravel), `PUT` as a full replacement, camelCase JSON, trailing slashes accepted, a CSP with `frame-ancestors 'none'`, a 404 for missing static files, and `color-named` back on.
     - Done in 7.2.0 (2026-11-11): the two Apache vhosts, the queue unit and the scheduler's cron file are templates in `deploy/` that every deploy installs; sessions are rows of the SQLite database under the hash of their id, and the cookie is `__Host-` prefixed on https; passwords are argon2id and rehashed at sign-in; limits count an IPv6 client by its /64; an admin can't remove their own role or delete their own account. Left from the sign-in rules: the remember token is stored as issued; the queue unit has no hardening block or memory cap.

<!-- -->

5. **Pilot on Kindred Nights, then the Gallery.** Kindred has Go, Vue, C#, PowerShell, Markdown and CI that runs; the Gallery adds PHP. Home Library has no commits or remote yet, so its CI has never run and it can't show whether the shared workflows work. Kindred's pilot is done (2026-11-05): v0.29.4 deployed on the shared module over one held connection. It found three shared bugs, fixed in v1.1.0 and v1.1.1; its plugin publish on the module is still to be run, at its next plugin release. The Gallery's pilot is done (2026-11-06): 7.0.1 deployed on the shared module, with the smoke check and its rollback, and it gained Dependabot. It found one shared bug, Vite's warning about `test/web/vitest.js`, fixed in v1.1.2. Both are on v1.1.2 with CI passing, the PHP workflow included. Both moved to v1.2.0 on 2026-11-06 and took the pieces decided after them:
   - Each deploy installs and checks the scheduled backup: Kindred's timer runs its own `kindred backup`, and the Gallery's runs the shared job into `db/backups/`. The Gallery's deploy now pulls the live database too.
   - Each has a basic end-to-end suite in CI: 9 tests on the Gallery and 11 on Kindred, each on a throwaway database.
   - Axe found real problems in both, and the apps fixed them. The Gallery lacked a `main` landmark and names on three icon-only buttons, its sign-in and sign-up pages had no `h1`, and its muted text was under 4.5:1. Kindred's front and not-found pages had no `h1`.
   - Both deployed with them on 2026-11-06, the Gallery from `02a8ba8` and Kindred as v0.30.0. The droplet ran both kinds of backup timer: the shared job (the Gallery) and a project's own command (Kindred). Both database pulls came down. Both suites passed in CI, the shared `e2e.yml`'s first runs: the Gallery's with PHP, Kindred's with Go and Font Awesome Pro.
6. **Roll out one repo at a time**, on `main`, with no separate branches. Everything shared-tooling can do lands in one pass: swap in the shared configs, move its deploy script onto the shared module, turn on its scheduled backup and database pull, and add its basic end-to-end suite, together (decided 2026-11-05, after Kindred's pilot did them on two branches; backups added 2026-11-06). Point its CLAUDE.md at the standards, trim what moved there, and close that project's gaps (see Project gaps). Small fixes ride along: the SQLite switch in Senpan and Modem Monitor, jsdom, and Playwright for the résumé site. After the pilots, the order is Senpan, Marxbot, TarotGen.io, then the rest (decided 2026-11-06).
   - **The compression rule's follow-ups** (decided 2026-11-06; done 2026-11-07, in v2.0.0):
     - All five rolled-out projects took v2.0.0 and deployed, which compressed their backups and snapshots.
     - Kindred's own `kindred backup` writes its daily copy compressed.
     - The Gallery's and TarotGen.io's deploy snapshots are the module's `Backup-RemoteDatabase`, and TarotGen.io's pull runs as www-data (`Copy-LiveDatabase -Owner`).
     - v2.0.0 also moved every project to Stylelint 18; TarotGen.io dropped `stylelint-config-recommended-scss` for `postcss-scss`.
   - **Fantasy Dossier** (prepared 2026-11-07 on v2.1.4, not yet committed), the first rollout to take the standards from the start:
     - The shared configs in place of the private `eslint-config` submodule, which is removed. With it gone no project uses `jwill89/eslint-config`: the owner archived the repo and deleted every `ESLINT_CONFIG_TOKEN` secret (2026-11-07), the last in Family Card Game.
     - CI on the shared workflows, with a release job; the deploy on SharedDeploy (a swap of `dist` and `.htaccess`, a smoke check through Cloudflare, rollback); a basic end-to-end suite with axe. It has no database, so no backup timer or pull.
     - Vitest specs moved to `src/__tests__/`, with the config in `vite.config.ts`; `noUncheckedIndexedAccess` found 29 places, now handled.
     - Font Awesome moved from the per-style Pro packages to the kit `@awesome.me/kit-4f2bd9b614` (classic solid, classic regular and brands), made on 2026-11-07 and shared since with the Gallery and Home Library.
     - Its e2e suite waits for the route's entrance animation (`settled` in `e2e/fixtures.ts`) before axe runs: axe run while a dossier page was still dropping in through its 3D transform stalled the browser for good, failing CI's first run.
   - **Font Awesome bandwidth** (2026-11-07). The account went over its November bandwidth: CI installed from the Pro registry 50 times in the first week (13 Kindred, 21 Senpan, 15 TarotGen.io, 1 Dossier), since every changed lockfile missed setup-node's exact-key npm cache, and a job that failed after installing saved nothing. Senpan's kit held all 17 styles, a 201 MB download each time, about 4.2 GB of the 4.6 GB.
     - v2.1.5's shared workflows restore npm's download cache from the newest earlier run and save both caches straight after the install, so a package is fetched again only when its own version changes. Home Library's own CI took the same scheme.
     - The owner cut every kit to the styles its sites import (Senpan's to four, 42 MB), and the guide now says a kit holds only those.
     - The Gallery stopped loading its kit from `kit.fontawesome.com` on every page view: it installs the shared kit and renders inline SVG through `<font-awesome-icon>`, with a test that fails on an unregistered icon. Home Library moved from the per-style packages to the same kit.
     - Kits are not cut to chosen icons (Font Awesome's custom subsetting), decided 2026-11-07: it would make each package a few hundred KB, but every new icon would first need adding to the kit and a new package version. A few MB per style, fetched only when a kit's version changes, is the price of adding an icon freely.
   - **Release tags from CI** (2026-11-07). The standard has CI tag each release, but Kindred, Marxbot and Home Library still tagged by hand or not at all; Kindred's standards pass had recorded it as a departure instead of adopting it, and its deploy then refused an untagged HEAD. All three now have the Dossier's `release` job and `release.sh`, reading `web/package.json` (Kindred 0.30.1, Home Library 1.1.0) or a `VERSION` file (Marxbot 1.0.0, which the bot embeds and logs), and each deploy fetches the tags and refuses a HEAD whose tag isn't that version. Kindred's hand tags had gone backwards (v0.29.5 and v0.29.6 after v0.30.0). The Gallery's, TarotGen.io's and the Dossier's deploys then took the same check (their CI already tagged releases; the Gallery hadn't recorded the gap as a departure), with `-Preview` for an untagged try. Senpan's waits for its move to one app version, since CI still tags its parts separately.

<!-- markdownlint-enable MD029 -->

## Ideas to skip

These came up and aren't worth doing:

- **Laravel Octane or FrankenPHP:** the Gallery's own measurements show Laravel adds only 3–5 ms, which disappears in the trip through Cloudflare.
