<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/**
 * Response for GET /version. Doc-only schema.
 */
#[OAS\Schema(component: 'VersionResponse', description: 'Application and API version.')]
class VersionResponse
{
    #[OAS\Property(schema: new OAS\Schema(type: 'string', description: 'Application version (SemVer).'))]
    public string $version = '';
    #[OAS\Property(schema: new OAS\Schema(type: 'string', description: 'HTTP API contract version (SemVer).'))]
    public string $api_version = '';
}
