# Contributing to Gallery

Thanks for your interest in improving Gallery! This file says how to report a problem and how a change gets in. How the project is built, its commands and the conventions a change follows are in **[AGENTS.md](AGENTS.md)**; the rules every one of the owner's projects follows are in [shared/standards/](shared/standards/README.md).

## Reporting a bug

Open an issue that gives:

- the steps that make it happen,
- what you expected,
- what happened instead, with any message the page showed,
- your environment: the browser and its version, and for a backend problem, the PHP and Node versions.

Report a security concern privately to the maintainer rather than opening a public issue.

## Proposing a change

Open an issue before anything beyond a small, obvious fix, so the change is agreed before it is built.

## Submitting a change

1. Work lands on `main`; there are no feature branches. Without push access, fork the repo and open a pull request against `main`.
2. Run `task check` from the repo root until it passes; it runs what CI runs. If you touched the API, commit the regenerated `backend/openapi.json` and `frontend/src/types/api.generated.ts` with the change (`composer docs`, `npm run gen:types`).
3. Add a bullet under **`## [Unreleased]`** in [CHANGELOG.md](CHANGELOG.md), in the right group (Added / Changed / Fixed / Removed / Security).
4. CI on GitHub is the gate: the change is done when CI is green on `main`.
