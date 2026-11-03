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
 * resources, and the other response shapes in Schemas/.
 */
#[OAS\Info(
    version: self::API_VERSION,
    title: 'Gallery API',
    description: <<<'MD'
        Internal HTTP API for the self-hosted media gallery — a **hybrid REST-RPC**
        interface served under the `/api` base path. All responses are JSON.

        **Conventions**
        - Reads return `200` with the resource or collection.
        - Creates return `201` with the created resource.
        - Updates (`PUT`/`PATCH`) return `200` with the updated resource.
        - Deletes return `204` with no body.
        - Non-CRUD actions (login, migrate, scan, bulk operations) are `POST`
          verbs that return `200` with a small result object.
        - Errors return `{ "error": "<MachineCode>", "message": "<human text>" }`
          with a `4xx`/`5xx` status. `error` is a stable PascalCase code safe to
          branch on; `message` is for display and may change.

        **Auth** — a Bearer token (`Authorization: Bearer <token>`) obtained from
        `POST /auth/login` (valid 24h). All `GET`s are public; state-changing
        methods require a token, except a small public allowlist (media tagging
        and the batched `POST /media/by-ids` read).
        MD,
)]
#[OAS\Server(url: '/api', description: 'Same-origin API base path')]
#[OAS\Security\Scheme(
    component: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    description: 'Admin bearer token issued by POST /auth/login (valid 24 hours).',
)]
#[OAS\Tag(name: 'System', description: 'Version and API documentation endpoints.')]
#[OAS\Tag(name: 'Auth', description: 'Admin authentication.')]
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
     * `api_version`. It moves only when the API surface changes, never with
     * an application release.
     */
    public const string API_VERSION = '4.0.0';
}
