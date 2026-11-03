<?php

namespace Gallery\Structure\Api;

use OpenApi\Spec as OAS;

/**
 * Response for GET /version. Doc-only schema.
 */
#[OAS\Schema(schema: 'VersionResponse', description: 'Application and API version.')]
class VersionResponse
{
    #[OAS\Property(schema: new OAS\Schema(type: 'string', description: 'Application version (SemVer).'))]
    public string $version = '';
    #[OAS\Property(schema: new OAS\Schema(type: 'string', description: 'HTTP API contract version (SemVer).'))]
    public string $api_version = '';
}
