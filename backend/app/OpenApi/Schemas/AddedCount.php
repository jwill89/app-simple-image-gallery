<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/** Response body: POST /lists/{listId}/media. Doc-only schema. */
#[OAS\Schema(
    component: 'AddedCount',
    description: 'How many media items a bulk add put in a list.',
    required: ['added'],
    properties: [
        new OAS\Property(property: 'added', schema: new OAS\Schema(type: 'integer', description: 'Items added that weren\'t in the list already.')),
    ],
)]
final class AddedCount {}
