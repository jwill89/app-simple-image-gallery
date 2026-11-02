# Gallery backend (Laravel)

The Laravel replacement for the Slim backend in `../backend/`, built beside it on the
`laravel-migration` branch. The plan, and how far it has got, is in
[docs/laravel-migration.md](../docs/laravel-migration.md). It must answer every request the
way the recordings in `../backend/tests/Fixtures/contract/` say the Slim app does. At the
plan's Phase 8 it replaces `backend/` and this README goes.

## Local setup

```bash
composer setup            # install, create .env from .env.example, generate APP_KEY
```

Then set `DB_DATABASE` in `.env` to an absolute path to a copy of the gallery database,
never `../backend/db/gallery.db` itself. `database/gallery.db` is gitignored for that
purpose. Fill in the `GALLERY_*` and `DANBOORU_*` values as in `../backend/.env`.

## Checks

The same gate as the Slim backend, and the `backend-laravel` CI job runs each one:

```bash
composer audit
composer lint             # Pint, PER preset (composer lint:fix applies)
composer rector:check     # PHP 8.5 idioms, dead code, Laravel rules (composer rector applies)
composer analyse          # Larastan, level 8 + strict and deprecation rules
composer test             # Pest
```
