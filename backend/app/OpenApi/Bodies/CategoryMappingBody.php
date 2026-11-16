<?php

declare(strict_types=1);

namespace App\OpenApi\Bodies;

use OpenApi\Spec as OAS;

/** Request body: POST /danbooru/category-mappings. Doc-only schema. */
#[OAS\Schema(
    component: 'CategoryMappingBody',
    description: 'Which gallery category a Danbooru category imports into; replaces any mapping it has.',
    required: ['danbooruCategoryId', 'danbooruCategoryName', 'galleryCategoryId'],
    properties: [
        new OAS\Property(property: 'danbooruCategoryId', schema: new OAS\Schema(type: 'integer', minimum: 0, description: 'Danbooru\'s number for the category (0 is General).')),
        new OAS\Property(property: 'danbooruCategoryName', schema: new OAS\Schema(type: 'string', maxLength: 50, description: 'Danbooru\'s name for it.')),
        new OAS\Property(property: 'galleryCategoryId', schema: new OAS\Schema(type: 'integer', description: 'The gallery category its tags import into.')),
    ],
)]
final class CategoryMappingBody {}
