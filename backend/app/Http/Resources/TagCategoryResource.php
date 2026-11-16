<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TagCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Spec as OAS;

/** @mixin TagCategory */
#[OAS\Schema(
    component: 'TagCategory',
    description: 'A tag category (name, color, description, sort order).',
    required: ['categoryId', 'categoryName', 'color', 'description', 'sortOrder'],
    properties: [
        new OAS\Property(property: 'categoryId', schema: new OAS\Schema(type: 'integer', description: 'The category\'s id.')),
        new OAS\Property(property: 'categoryName', schema: new OAS\Schema(type: 'string', description: 'Unique without regard to case.')),
        new OAS\Property(property: 'color', schema: new OAS\Schema(type: 'string', nullable: true, description: 'Tag palette hue. Display only — never a semantic state name.')),
        new OAS\Property(property: 'description', schema: new OAS\Schema(type: 'string', nullable: true, description: 'What the category is for.')),
        new OAS\Property(property: 'sortOrder', schema: new OAS\Schema(type: 'integer', nullable: true, description: 'Where its tags come in a media item\'s tag list: lowest first.')),
    ],
)]
final class TagCategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'categoryId' => $this->category_id,
            'categoryName' => $this->category_name,
            'color' => $this->color,
            'description' => $this->description,
            'sortOrder' => $this->sort_order,
        ];
    }
}
