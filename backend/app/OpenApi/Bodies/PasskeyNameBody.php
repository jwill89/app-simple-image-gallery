<?php

declare(strict_types=1);

namespace App\OpenApi\Bodies;

use OpenApi\Spec as OAS;

/** Request body: PATCH /account/passkeys/{passkeyId}. Doc-only schema. */
#[OAS\Schema(
    component: 'PasskeyNameBody',
    description: 'A passkey\'s new name.',
    required: ['name'],
    properties: [
        new OAS\Property(property: 'name', schema: new OAS\Schema(type: 'string', maxLength: 64, description: 'The new name.')),
    ],
)]
final class PasskeyNameBody {}
