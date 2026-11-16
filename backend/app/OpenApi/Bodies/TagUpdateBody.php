<?php

declare(strict_types=1);

namespace App\OpenApi\Bodies;

use OpenApi\Spec as OAS;

/** Request body: PATCH /tags/{tagId}. Doc-only schema. */
#[OAS\Schema(
    component: 'TagUpdateBody',
    description: 'A tag\'s changes: the fields sent change, the rest stay.',
    properties: [
        new OAS\Property(property: 'tagName', schema: new OAS\Schema(type: 'string', maxLength: 100, description: 'A new name; stored lowercase and trimmed.')),
        new OAS\Property(property: 'categoryId', schema: new OAS\Schema(type: 'integer', description: 'A new category.')),
    ],
)]
final class TagUpdateBody {}
