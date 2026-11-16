<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/** Response body: GET /media/count. Doc-only schema. */
#[OAS\Schema(
    component: 'Count',
    description: 'How many media items the reader may see.',
    required: ['count'],
    properties: [
        new OAS\Property(property: 'count', schema: new OAS\Schema(type: 'integer', description: 'The number of items, less any carrying a tag the account blocks.')),
    ],
)]
final class Count {}
