<?php

declare(strict_types=1);

namespace App\OpenApi\Bodies;

use OpenApi\Spec as OAS;

/** Request body: POST /auth/login. Doc-only schema. */
#[OAS\Schema(
    component: 'LoginBody',
    description: 'A sign-in by username and password.',
    required: ['username', 'password'],
    properties: [
        new OAS\Property(property: 'username', schema: new OAS\Schema(type: 'string', description: 'Any case.')),
        new OAS\Property(property: 'password', schema: new OAS\Schema(type: 'string', description: 'The account\'s password.')),
        new OAS\Property(property: 'remember', schema: new OAS\Schema(type: 'boolean', description: 'Keep this browser signed in for 30 days, not just until it closes.')),
        new OAS\Property(property: 'turnstileToken', schema: new OAS\Schema(type: 'string', description: 'The Turnstile widget\'s token (action "login"), when GET /auth/config gives a site key.')),
    ],
)]
final class LoginBody {}
