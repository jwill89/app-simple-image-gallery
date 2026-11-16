<?php

declare(strict_types=1);

namespace App\OpenApi\Bodies;

use OpenApi\Spec as OAS;

/** Request body: POST /lists/{listId}/media. Doc-only schema. */
#[OAS\Schema(
    component: 'ListItemsBody',
    description: 'Media items to add to a list, by id.',
    required: ['mediaIds'],
    properties: [
        new OAS\Property(property: 'mediaIds', schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(type: 'integer'), maxItems: 2000, description: 'Up to 2000 ids; ids with no item are skipped.')),
    ],
)]
final class ListItemsBody {}
