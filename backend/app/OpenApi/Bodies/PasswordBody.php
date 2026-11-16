<?php

declare(strict_types=1);

namespace App\OpenApi\Bodies;

use OpenApi\Spec as OAS;

/** Request body: DELETE /account and POST /account/passkeys/options. Doc-only schema. */
#[OAS\Schema(
    component: 'PasswordBody',
    description: 'The account\'s password, confirming who is asking.',
    required: ['password'],
    properties: [
        new OAS\Property(property: 'password', schema: new OAS\Schema(type: 'string', description: 'The account\'s current password.')),
    ],
)]
final class PasswordBody {}
