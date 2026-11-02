# Contributing to Gallery

Thanks for your interest in improving Gallery! This guide covers local setup, the
coding standards CI enforces, and the pull-request flow. For the deeper architecture,
data model, and conventions, read **[AGENTS.md](AGENTS.md)** first.

## Project layout

```
backend/    PHP 8.5 + Slim 4 API, SQLite, Phinx migrations, CLI scripts
frontend/   Vue 3 + TypeScript SPA (Vite, Pinia, Vue Router, Bulma)
scripts/    Dev-only deploy script (not part of the app; gitignored)
```

The droplet webroot is flat; the deploy flattens `backend/` + `frontend/dist` onto
it. See [AGENTS.md §2](AGENTS.md) for the full tree.

## Prerequisites

- **PHP 8.5+** with `pdo_sqlite`, `curl`, `fileinfo`, `mbstring`, and **GD** (WebP, ideally AVIF).
- **Composer** and **Node.js 26+** (with npm).
- **`ffmpeg`** on your `PATH` (video/animated thumbnails).

## Local setup

**Backend** (run from `backend/`):

```bash
cd backend
composer install
cp .env.example .env     # then fill in real values — see AGENTS.md §3 for what each key does
php db/setup.php         # creates db/gallery.db and runs migrations
```

Serve `backend/` with Apache + `mod_rewrite` (it is the app root). At minimum set
`GALLERY_ADMIN_PASSWORD` in `backend/.env`, or admin login stays disabled.

**Frontend** (run from `frontend/`):

```bash
git submodule update --init --recursive   # fetch frontend/eslint-config (shared lint config)
cd frontend
npm install
npm run dev             # Vite dev server on :5173, proxies /api and /media to http://localhost
```

> Clone with `git clone --recurse-submodules …` to fetch the submodule up front. Without the
> submodule checked out, `npm install` fails (it resolves `@jwill89/eslint-config` from `file:eslint-config`).

## Coding standards

CI runs these checks on every push and pull request — please run them locally first.

**PHP — PER-CS 3.0** (PSR-12's successor, enforced by PHP CS Fixer; config in
`backend/.php-cs-fixer.dist.php`), plus static analysis, Rector and dependency checks:

```bash
cd backend
composer lint           # PHP CS Fixer: must report no files to fix
composer lint:fix       # apply the fixes
composer analyse        # PHPStan level 8 + strict and deprecation rules: must report no errors
composer rector:check   # Rector (PHP 8.5 idioms, dead code): must propose no changes
composer rector         # apply Rector's changes (review them before committing)
composer deps:check     # composer-unused + composer-require-checker
composer normalize      # keep composer.json in normalized order (CI runs --dry-run)
```

Keep SQL in the `Repository`/`Storage` layer, return responses via the
`AbstractController` helpers (`success()` / `created()` / `noContent()` / `error()`),
and follow the layered flow described in AGENTS.md. **Annotate every new/changed
endpoint** with `#[OAS\...]` attributes (and structure classes with `#[OAS\Schema]`),
then regenerate the committed spec:

```bash
cd backend
composer docs           # regenerate backend/openapi.json — CI fails if it drifts
```

**Frontend — TypeScript + Vue 3** (`<script setup lang="ts">`, Composition API only):

```bash
cd frontend
npm run lint            # ESLint (type-checked, incl. template accessibility): must report 0 errors
npm run lint:fix        # auto-fix what it can
npm run lint:css        # Stylelint: standard rules + the palette rule
npm run lint:css:fix    # auto-fix what it can
npm run knip            # Knip: no unused files, exports or dependencies
npm run format          # Prettier: format the frontend
npm run format:check    # Prettier: verify formatting (CI gate)
npm run build           # vue-tsc type-check + vite build; must succeed with no type errors
```

The lint rules and Prettier config are shared across projects via the
**[@jwill89/eslint-config](https://github.com/jwill89/eslint-config)** git submodule at
`frontend/eslint-config` (Vue + TypeScript **strictTypeChecked**). `prettier.config.js`
re-exports it; `eslint.config.ts` spreads it and adds this project's own rules (template
accessibility, the Vue style-guide rules behind the conventions in AGENTS.md §8,
typescript-eslint's stylistic set, no `fetch` outside `useApi.ts`). Change a rule every
project should get **in the submodule** (commit + push there, then bump the submodule pointer
here); keep project-specific ones in `eslint.config.ts`. Conventions the rules enforce:
prefer `catch (e)` (typed `unknown`) with the `getErrorMessage`/`getErrorStatus`/`getErrorCode`
helpers from `composables/useApi.ts` over `catch (e: any)`; `void`-prefix intentional
fire-and-forget promises (`void router.push(...)`); prefix genuinely-unused vars/args with `_`.

Route API calls through `composables/useApi.ts` (never raw `fetch`) using the paths in
`src/api/endpoints.ts`. Domain types are **generated from the OpenAPI spec** — after a
backend contract change, regenerate them and commit the result (CI checks it's current):

```bash
cd frontend
npm run gen:types       # → src/types/api.generated.ts (openapi-typescript)
```

Import types from `src/types` (friendly aliases over the generated file), not the
generated module directly.

## Tests

Backend (PHPUnit 13, from `backend/`):

```bash
composer test            # run the suite
composer test:coverage   # with a coverage report (needs pcov or xdebug)
```

Tests live in `backend/tests/Unit/` (organized by layer) and run against an in-memory
SQLite database built from `backend/tests/Support/schema.sql` — a flattened snapshot of
the Phinx schema. **If you add or change a migration, regenerate that snapshot** (migrate a
scratch SQLite file and dump `SELECT sql FROM sqlite_master`), preserving the manual edits
documented at the top of the file.

Code is wired for testability via **constructor dependency injection**: Storage/Collection
classes (and `RateLimiter`) receive their `PDO`/dependencies through the constructor, and the
PHP-DI container in `backend/api/dependencies.php` supplies them in production. Keep new
DB-bound classes injectable rather than calling `DatabaseConnection::getInstance()` directly.

Frontend (Vitest, from `frontend/`):

```bash
npm run test             # run once
npm run test:watch       # watch mode
npm run test:coverage    # with a coverage report
```

Specs live in `frontend/src/__tests__/*.spec.ts` (happy-dom).

## Database changes

The schema lives in Phinx migrations (`backend/db/migrations/`) — the single source
of truth. **Never hand-edit `db/setup.php` to change the schema.** Add a migration:

```bash
cd backend
php vendor/bin/phinx create AddSomethingUseful
php vendor/bin/phinx migrate
```

## Pull-request flow

1. Branch off `master`.
2. Make your change; run the lint, test, and build checks above until they pass.
   If you touched the API, run `composer docs` + `npm run gen:types` and commit the
   regenerated `backend/openapi.json` and `frontend/src/types/api.generated.ts`.
3. Add a bullet under the **`## [Unreleased]`** heading in
   [CHANGELOG.md](CHANGELOG.md), in the right group (Added / Changed / Fixed /
   Removed / Security). We follow [Keep a Changelog](https://keepachangelog.com/)
   and [Semantic Versioning](https://semver.org/).
4. Open a PR against `master`. CI (lint, tests, build) must be green.

## Security & secrets

- **Never commit secrets.** `backend/.env` (admin password, Danbooru API key) and
  `scripts/deploy.ps1` (host IP, SSH key path) are gitignored — keep them that way.
- The SSH private key lives **outside** the repo; only its path is referenced.
- Report a security concern privately to the maintainer rather than opening a public
  issue.

## Releasing (maintainers)

1. Move the `## [Unreleased]` entries under a new `## [x.y.z] - YYYY-MM-DD` heading
   and update the compare links at the bottom of `CHANGELOG.md`.
2. Bump the version in **`frontend/package.json`**, **`backend/composer.json`**, and
   `Gallery\Core\Configuration::VERSION` (the footer and `GET /api/version` read these),
   then run `composer docs` so the spec's `info.version` matches.
3. Tag the release `vX.Y.Z` and deploy with `scripts/deploy.ps1`.
