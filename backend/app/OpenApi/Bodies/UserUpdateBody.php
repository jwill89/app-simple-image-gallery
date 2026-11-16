<?php

declare(strict_types=1);

namespace App\OpenApi\Bodies;

use OpenApi\Spec as OAS;

/** Request body: PATCH /users/{userId}. Doc-only schema. */
#[OAS\Schema(
    component: 'UserUpdateBody',
    description: 'An admin\'s changes to an account: the fields sent change, the rest stay.',
    properties: [
        new OAS\Property(property: 'isAdmin', schema: new OAS\Schema(type: 'boolean', description: 'Make it an admin, or not.')),
        new OAS\Property(property: 'password', schema: new OAS\Schema(type: 'string', maxLength: 200, minLength: 8, description: 'A new password, which signs it out everywhere.')),
    ],
)]
final class UserUpdateBody {}
