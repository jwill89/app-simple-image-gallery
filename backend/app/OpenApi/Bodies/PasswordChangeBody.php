<?php

declare(strict_types=1);

namespace App\OpenApi\Bodies;

use OpenApi\Spec as OAS;

/** Request body: PATCH /account/password. Doc-only schema. */
#[OAS\Schema(
    component: 'PasswordChangeBody',
    description: 'A new password for the signed-in account.',
    required: ['currentPassword', 'password'],
    properties: [
        new OAS\Property(property: 'currentPassword', schema: new OAS\Schema(type: 'string', description: 'The password now.')),
        new OAS\Property(property: 'password', schema: new OAS\Schema(type: 'string', maxLength: 200, minLength: 8, description: 'The new one, at least 8 characters.')),
    ],
)]
final class PasswordChangeBody {}
