<?php

declare(strict_types=1);

namespace App\OpenApi\Bodies;

use OpenApi\Spec as OAS;

/** Request body: POST /auth/register. Doc-only schema. */
#[OAS\Schema(
    component: 'RegisterBody',
    description: 'A new account.',
    required: ['username', 'password'],
    properties: [
        new OAS\Property(property: 'username', schema: new OAS\Schema(type: 'string', maxLength: 32, minLength: 3, pattern: '^[A-Za-z0-9._-]{3,32}$', description: '3 to 32 letters, digits, dots, dashes or underscores; unique without regard to case.')),
        new OAS\Property(property: 'password', schema: new OAS\Schema(type: 'string', maxLength: 200, minLength: 8, description: 'At least 8 characters.')),
        new OAS\Property(property: 'remember', schema: new OAS\Schema(type: 'boolean', description: 'Keep this browser signed in for 30 days, not just until it closes.')),
        new OAS\Property(property: 'turnstileToken', schema: new OAS\Schema(type: 'string', description: 'The Turnstile widget\'s token (action "register"), when GET /auth/config gives a site key.')),
    ],
)]
final class RegisterBody {}
