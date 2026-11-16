<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DanbooruCategoryMapping;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Spec as OAS;

/**
 * A Danbooru category mapping with its gallery category's name.
 *
 * @mixin DanbooruCategoryMapping
 */
#[OAS\Schema(
    component: 'CategoryMapping',
    description: 'Which gallery category a Danbooru tag category imports into.',
    required: ['danbooruCategoryId', 'danbooruCategoryName', 'galleryCategoryId', 'galleryCategoryName'],
    properties: [
        new OAS\Property(property: 'danbooruCategoryId', schema: new OAS\Schema(type: 'integer', description: 'Danbooru\'s number for the category (0 is General).')),
        new OAS\Property(property: 'danbooruCategoryName', schema: new OAS\Schema(type: 'string', description: 'Danbooru\'s name for it.')),
        new OAS\Property(property: 'galleryCategoryId', schema: new OAS\Schema(type: 'integer', description: 'The gallery category its tags import into.')),
        new OAS\Property(property: 'galleryCategoryName', schema: new OAS\Schema(type: 'string', nullable: true, description: 'That category\'s name.')),
    ],
)]
final class CategoryMappingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'danbooruCategoryId' => $this->danbooru_category_id,
            'danbooruCategoryName' => $this->danbooru_category_name,
            'galleryCategoryId' => $this->gallery_category_id,
            'galleryCategoryName' => $this->category?->category_name,
        ];
    }
}
