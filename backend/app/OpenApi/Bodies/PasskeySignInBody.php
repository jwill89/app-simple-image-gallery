<?php

declare(strict_types=1);

namespace App\OpenApi\Bodies;

use OpenApi\Spec as OAS;

/** Request body: POST /auth/passkey. Doc-only schema. */
#[OAS\Schema(
    component: 'PasskeySignInBody',
    description: 'A passkey\'s answer to the sign-in challenge.',
    required: ['credential'],
    properties: [
        new OAS\Property(property: 'credential', schema: new OAS\Schema(type: 'object', additionalProperties: true, description: 'The PublicKeyCredential navigator.credentials.get() gave, as its toJSON() writes it.')),
        new OAS\Property(property: 'remember', schema: new OAS\Schema(type: 'boolean', description: 'Keep this browser signed in for 30 days, not just until it closes.')),
    ],
)]
final class PasskeySignInBody {}
