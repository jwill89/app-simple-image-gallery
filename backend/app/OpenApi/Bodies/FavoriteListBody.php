<?php

declare(strict_types=1);

namespace App\OpenApi\Bodies;

use OpenApi\Spec as OAS;

/** Request body: POST /lists and PATCH /lists/{listId}. Doc-only schema. */
#[OAS\Schema(
    component: 'FavoriteListBody',
    description: 'A list\'s name.',
    required: ['name'],
    properties: [
        new OAS\Property(property: 'name', schema: new OAS\Schema(type: 'string', maxLength: 60, description: 'Unique among the account\'s lists, without regard to case.')),
    ],
)]
final class FavoriteListBody {}
