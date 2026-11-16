<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/** Response body: GET /version. Doc-only schema. */
#[OAS\Schema(
    component: 'VersionResponse',
    description: 'Application and API version.',
    required: ['version', 'apiVersion'],
    properties: [
        new OAS\Property(property: 'version', schema: new OAS\Schema(type: 'string', description: 'Application version (SemVer).')),
        new OAS\Property(property: 'apiVersion', schema: new OAS\Schema(type: 'string', description: 'HTTP API contract version (SemVer); moves only when the API changes.')),
    ],
)]
final class VersionResponse {}
