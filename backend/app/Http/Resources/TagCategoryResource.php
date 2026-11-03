<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TagCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Spec as OAS;

/** @mixin TagCategory */
#[OAS\Schema(component: 'TagCategory', description: 'A tag category (color, shortcode, sort order).', properties: [
    new OAS\Property(property: 'category_id', schema: new OAS\Schema(type: 'integer')),
    new OAS\Property(property: 'category_name', schema: new OAS\Schema(type: 'string')),
    new OAS\Property(property: 'color', schema: new OAS\Schema(type: 'string', description: 'Tag palette hue. Display only — never a semantic state name.')),
    new OAS\Property(property: 'description', schema: new OAS\Schema(type: 'string')),
    new OAS\Property(property: 'sort_order', schema: new OAS\Schema(type: 'integer')),
])]
final class TagCategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'category_id' => $this->category_id,
            'category_name' => $this->category_name,
            'color' => $this->color,
            'description' => $this->description,
            'sort_order' => $this->sort_order,
        ];
    }
}
