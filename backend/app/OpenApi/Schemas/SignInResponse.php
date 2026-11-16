<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/** Response body: POST /auth/register, /auth/login and /auth/passkey. Doc-only schema. */
#[OAS\Schema(component: 'SignInResponse', description: 'The account this browser is now signed in as.', required: ['user'], properties: [
    new OAS\Property(property: 'user', schema: new OAS\Schema(description: 'The account.', allOf: [new OAS\Schema(ref: '#/components/schemas/User')])),
])]
final class SignInResponse {}
