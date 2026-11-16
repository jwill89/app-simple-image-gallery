<?php

declare(strict_types=1);

namespace App\OpenApi\Bodies;

use OpenApi\Spec as OAS;

/** Request body: POST /tags. Doc-only schema. */
#[OAS\Schema(
    component: 'TagCreateBody',
    description: 'A new tag.',
    required: ['tagName'],
    properties: [
        new OAS\Property(property: 'tagName', schema: new OAS\Schema(type: 'string', maxLength: 100, description: 'The name; stored lowercase and trimmed.')),
        new OAS\Property(property: 'categoryId', schema: new OAS\Schema(type: 'integer', default: 1, description: 'Its category; General (1) when left out.')),
    ],
)]
final class TagCreateBody {}
