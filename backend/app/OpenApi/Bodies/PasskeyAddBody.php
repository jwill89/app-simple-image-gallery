<?php

declare(strict_types=1);

namespace App\OpenApi\Bodies;

use OpenApi\Spec as OAS;

/** Request body: POST /account/passkeys. Doc-only schema. */
#[OAS\Schema(
    component: 'PasskeyAddBody',
    description: 'A new passkey\'s answer to the challenge.',
    required: ['credential'],
    properties: [
        new OAS\Property(property: 'credential', schema: new OAS\Schema(type: 'object', additionalProperties: true, description: 'The PublicKeyCredential navigator.credentials.create() gave, as its toJSON() writes it.')),
        new OAS\Property(property: 'name', schema: new OAS\Schema(type: 'string', maxLength: 64, description: 'What to call it; left out, it is named after where it is kept, when its maker says.')),
    ],
)]
final class PasskeyAddBody {}
