<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/**
 * Response for POST /auth/login and POST /auth/register. Doc-only schema.
 */
#[OAS\Schema(component: 'LoginResponse', description: 'A bearer token, and the account it signs in.')]
class LoginResponse
{
    #[OAS\Property(schema: new OAS\Schema(type: 'string', description: 'Bearer token, valid for a day, or 30 with remember.'))]
    public string $token = '';

    #[OAS\Property(schema: new OAS\Schema(ref: '#/components/schemas/User'))]
    public ?object $user = null;
}
