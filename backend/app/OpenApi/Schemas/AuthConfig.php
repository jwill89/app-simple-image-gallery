<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/** Response body: GET /auth/config. Doc-only schema. */
#[OAS\Schema(
    component: 'AuthConfig',
    description: 'What the sign-up and sign-in pages need to know.',
    required: ['signupOpen', 'turnstileSiteKey'],
    properties: [
        new OAS\Property(property: 'signupOpen', schema: new OAS\Schema(type: 'boolean', description: 'Whether anyone may make an account.')),
        new OAS\Property(property: 'turnstileSiteKey', schema: new OAS\Schema(type: 'string', nullable: true, description: 'Cloudflare Turnstile\'s site key for the bot check; null while the server doesn\'t check.')),
    ],
)]
final class AuthConfig {}
