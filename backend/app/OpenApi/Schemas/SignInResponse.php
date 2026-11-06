<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/**
 * Response for POST /auth/login, POST /auth/register and POST /auth/passkey:
 * the account the browser is now signed in as. The session itself is the
 * cookie that comes with it. Doc-only schema.
 */
#[OAS\Schema(component: 'SignInResponse', description: 'The account this browser is now signed in as.', required: ['user'])]
class SignInResponse
{
    #[OAS\Property(schema: new OAS\Schema(ref: '#/components/schemas/User'))]
    public ?object $user = null;
}
