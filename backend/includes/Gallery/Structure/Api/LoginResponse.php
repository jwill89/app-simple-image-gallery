<?php

namespace Gallery\Structure\Api;

use OpenApi\Spec as OAS;

/**
 * Response for POST /auth/login. Doc-only schema.
 */
#[OAS\Schema(schema: 'LoginResponse', description: 'Issued admin bearer token.')]
class LoginResponse
{
    #[OAS\Property(schema: new OAS\Schema(type: 'string', description: 'Bearer token, valid for 24 hours.'))]
    public string $token = '';
}
