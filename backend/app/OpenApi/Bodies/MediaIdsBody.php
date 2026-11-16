<?php

declare(strict_types=1);

namespace App\OpenApi\Bodies;

use OpenApi\Spec as OAS;

/** Request body: POST /media/by-ids. Doc-only schema. */
#[OAS\Schema(
    component: 'MediaIdsBody',
    description: 'Media items to read, by id.',
    required: ['ids'],
    properties: [
        new OAS\Property(property: 'ids', schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(type: 'integer'), maxItems: 200, minItems: 1, description: 'Up to 200 ids; ids with no item are skipped.')),
    ],
)]
final class MediaIdsBody {}
