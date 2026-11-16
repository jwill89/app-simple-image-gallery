<?php

declare(strict_types=1);

namespace App\OpenApi\Bodies;

use OpenApi\Spec as OAS;

/** Request body: PATCH /tag-categories/{categoryId}. Doc-only schema. */
#[OAS\Schema(
    component: 'TagCategoryUpdateBody',
    description: 'A category\'s changes: the fields sent change, the rest stay.',
    properties: [
        new OAS\Property(property: 'categoryName', schema: new OAS\Schema(type: 'string', maxLength: 50, description: 'A new name; unique without regard to case.')),
        new OAS\Property(property: 'color', schema: new OAS\Schema(type: 'string', description: 'A tag palette hue (TagCategory::COLORS).')),
        new OAS\Property(property: 'description', schema: new OAS\Schema(type: 'string', nullable: true, description: 'What the category is for.')),
        new OAS\Property(property: 'sortOrder', schema: new OAS\Schema(type: 'integer', nullable: true, description: 'Where its tags come in a tag list: lowest first.')),
    ],
)]
final class TagCategoryUpdateBody {}
