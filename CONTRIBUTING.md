# Contributing to Gallery

Thanks for your interest in improving Gallery! This guide covers local setup, the
coding standards CI enforces, and the pull-request flow. For the deeper architecture,
data model, and conventions, read **[AGENTS.md](AGENTS.md)** first.

## Project layout

```
backend/    PHP 8.5 + Laravel 13 API and SPA shell, SQLite, queued jobs, artisan commands
frontend/   Vue 3 + TypeScript SPA (Vite, Pinia, Vue Router, Bulma)
scripts/    Dev-only deploy script and its config template
docs/       The Slim → Laravel migration plan and its record
```

On the droplet, `backend/public/` is Apache's DocumentRoot; the deploy copies the built
`frontend/dist` into it. See [AGENTS.md §2](AGENTS.md) for the full tree and §3 for the droplet.

## Prerequisites

- **PHP 8.5+** with `pdo_sqlite`, `curl`, `fileinfo`, `mbstring`, and **GD** (WebP, ideally AVIF).
- **Composer** and **Node.js 26+** (with npm).
- **`ffmpeg`/`ffprobe`** on your `PATH` (thumbnails, video metadata).

## Local setup

**Backend** (run from `backend/`):

```bash
cd backend
composer setup           # composer install, .env from .env.example, APP_KEY
# then set DB_DATABASE in .env to an absolute path to a copy of the gallery database
php artisan migrate
composer dev             # php artisan serve on :8000
```

Never point `DB_DATABASE` at a database another app is using. Set at least
`GALLERY_ADMIN_PASSWORD` in `backend/.env`, or admin login stays disabled; the other keys are
described in `.env.example` and [AGENTS.md §3](AGENTS.md).

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

**PHP — Pint** (the `per` preset, PSR-12's successor; config in `backend/pint.json`), plus
static analysis and Rector:

```bash
cd backend
composer lint           # Pint: must report no files to fix
composer lint:fix       # apply the fixes
composer analyse        # Larastan level 8 + strict and deprecation rules: must report no errors
composer rector:check   # Rector (PHP 8.5 and Laravel idioms, dead code): must propose no changes
composer rector         # apply Rector's changes (review them before committing)
```

Follow the layering in AGENTS.md: routes in `routes/api.php` (writes inside the `auth:sanctum`
group), validation in a Form Request extending `ApiRequest`, controllers that return Resources
and raise `ApiException`s, domain rules on the models, larger work in services and queued jobs.
**Annotate every new/changed endpoint** with `#[OAS\...]` attributes and a readable
`operationId` (and doc-only response classes in `app/OpenApi/Schemas/` with `#[OAS\Schema]`),
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

Backend (Pest 5, from `backend/`):

```bash
composer test            # the whole suite, including the contract replay
```

- **Feature tests** (`tests/Feature/`) run on a fresh in-memory SQLite database per test. Test
  through HTTP where you can, and fake what leaves the app: `Http::fake()` for Danbooru,
  `Process::fake()` for ffmpeg/ffprobe, `Queue::fake()` when only the dispatch matters.
- **The contract replay** (`tests/Feature/Contract/`) sends the 191 requests recorded from the
  Slim app (`tests/Fixtures/contract/`) and checks each answer. The recordings are frozen: a
  change to what the API answers on purpose goes in `tests/Feature/Contract/divergences.php` with
  its reason, never into a recording.
- **`SchemaTest`** checks that a fresh `migrate` builds the production schema
  (`tests/Fixtures/schema.sql`). A migration that changes a gallery table updates what it expects
  in the same change.

Frontend (Vitest, from `frontend/`):

```bash
npm run test             # run once
npm run test:watch       # watch mode
npm run test:coverage    # with a coverage report
```

Specs live in `frontend/src/__tests__/*.spec.ts` (happy-dom). Component specs mount a view with
`@vue/test-utils` and fake the server by mocking `useApi` (see `UploadView.spec.ts`).

## Database changes

The schema lives in Laravel migrations (`backend/database/migrations/`). Add a migration;
never edit one that has run in production:

```bash
cd backend
php artisan make:migration add_something_useful
php artisan migrate
```

## Pull-request flow

1. Branch off `main`.
2. Make your change; run the lint, test, and build checks above until they pass.
   If you touched the API, run `composer docs` + `npm run gen:types` and commit the
   regenerated `backend/openapi.json` and `frontend/src/types/api.generated.ts`.
3. Add a bullet under an **`## [Unreleased]`** heading in [CHANGELOG.md](CHANGELOG.md) (create
   it at the top if there isn't one), in the right group (Added / Changed / Fixed / Removed /
   Security). We follow [Keep a Changelog](https://keepachangelog.com/) and
   [Semantic Versioning](https://semver.org/).
4. Open a PR against `main`. CI (lint, tests, build) must be green.

## Security & secrets

- **Never commit secrets.** `backend/.env` (admin password, Danbooru API key, `APP_KEY`) and
  `scripts/deploy.config.ps1` (host, SSH user, key path) are gitignored — keep them that way.
- The SSH private key lives **outside** the repo; only its path is referenced.
- Report a security concern privately to the maintainer rather than opening a public
  issue.

## Releasing (maintainers)

1. Turn the `## [Unreleased]` heading into `## [x.y.z] - YYYY-MM-DD` and add its compare link at
   the bottom of `CHANGELOG.md`.
2. Bump the version in **`frontend/package.json`** (and `package-lock.json`, with
   `npm version x.y.z --no-git-tag-version`) and in **`backend/config/gallery.php`** (`version`,
   which `GET /api/version` serves; the footer reads `package.json`). Move
   `App\OpenApi\OpenApiSpec::API_VERSION` only if the API surface changed, then run `composer docs`.
3. Merge to `main`: once CI is green, it tags `vX.Y.Z` and publishes the release from that
   version's CHANGELOG section. Deploy with `scripts/deploy.ps1`.
