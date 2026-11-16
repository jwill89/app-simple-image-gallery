<?php

declare(strict_types=1);

namespace App\OpenApi;

use OpenApi\Spec as OAS;

/**
 * Global OpenAPI document metadata.
 *
 * This class carries no logic — it exists only to host the top-level
 * `#[OA\*]` attributes (info, server, security scheme, tag groups) that
 * swagger-php merges into the generated `openapi.json`. Per-endpoint
 * operations live on the controllers; per-model schemas live on the API
 * resources, request bodies in Bodies/, and the other response shapes in
 * Schemas/.
 */
#[OAS\Info(
    version: self::API_VERSION,
    title: 'Gallery API',
    description: <<<'MD'
        HTTP API for the self-hosted media gallery, served under the `/api` base
        path. Bodies are JSON (an upload is `multipart/form-data`), and every JSON
        field and query parameter is camelCase.

        **Conventions**
        - Reads return `200` with the resource or collection. A paged list takes
          `limit` (at most 100) and `offset` and sends its size in `X-Total-Count`;
          `GET /media` also pages by `cursor`, from the `nextCursor` each page carries.
        - Creates return `201` with the created resource.
        - Updates are `PATCH` and change only the fields sent; they return `200`
          with the updated resource. A `PUT` with no body adds something to a set
          (a list item, a blocked tag).
        - Deletes return `204` with no body.
        - Non-CRUD actions (login, migrate, scan, bulk operations) are `POST`
          verbs that return `200` with a small result object.
        - A trailing slash is ignored: `/api/tags/` is `/api/tags`.
        - Timestamps are RFC 3339 in UTC, to the second (`2026-11-07T09:18:09Z`).
        - Errors are RFC 9457 problem details (`application/problem+json`):
          `{ "type", "title", "status", "detail" }`, where `type` is a tag URI
          ending in a stable PascalCase name to branch on
          (`tag:gallery.mathdad.me,2026:MediaNotFound`) and `detail` is for
          display. A failed validation is `422` and adds `errors`, one
          `{ "message", "location" }` per broken field (`body.tagName`,
          `query.limit`). Malformed JSON is `400`; a rate limit is `429` with
          `Retry-After`.

        **Auth** — a session cookie, set by `POST /auth/login`, `POST /auth/register`
        or `POST /auth/passkey`, which the browser sends with every request. It is
        HttpOnly, so the page's scripts never see it; `GET /auth/session` says who
        it signs in. Writes are refused (`403 ForbiddenOrigin`) unless the browser
        marks them same-origin (`Sec-Fetch-Site`) and they come from an allowed
        origin. Reads are public; a write needs a signed-in account (or an admin),
        except a small public allowlist (media tagging and the batched
        `POST /media/by-ids` read).
        MD,
)]
#[OAS\Server(url: '/api', description: 'Same-origin API base path')]
#[OAS\Security\Scheme(
    component: 'sessionCookie',
    type: 'apiKey',
    in: 'cookie',
    name: '__Host-gallery-session',
    description: 'The session cookie a sign-in sets: until the browser closes, or 30 days with "keep me signed in". Over plain http (local development) it is named gallery-session.',
)]
#[OAS\Tag(name: 'System', description: 'Version and API documentation endpoints.')]
#[OAS\Tag(name: 'Auth', description: 'Sign-up, sign-in (password or passkey) and sign-out.')]
#[OAS\Tag(name: 'Media', description: 'Media items (images + videos), listing, upload, and tagging.')]
#[OAS\Tag(name: 'Tags', description: 'Tags CRUD and migration.')]
#[OAS\Tag(name: 'Tag Categories', description: 'Tag category CRUD.')]
#[OAS\Tag(name: 'Tag Implications', description: 'Tag implication relationships.')]
#[OAS\Tag(name: 'Danbooru', description: 'Danbooru import rule mappings.')]
#[OAS\Tag(name: 'Duplicates', description: 'Perceptual-hash duplicate detection.')]
final class OpenApiSpec
{
    /**
     * The HTTP API contract version (SemVer), served by GET /api/version as
     * `apiVersion`. It moves only when the API surface changes, never with
     * an application release.
     */
    public const string API_VERSION = '8.0.0';
}
