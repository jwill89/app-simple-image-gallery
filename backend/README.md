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

## Checks

CI's `backend` job runs each of these:

```bash
composer audit
composer lint             # Pint, PER preset (composer lint:fix applies)
composer rector:check     # PHP 8.5 idioms, dead code, Laravel rules (composer rector applies)
composer analyse          # Larastan, level 8 + strict and deprecation rules
composer test             # Pest
```
