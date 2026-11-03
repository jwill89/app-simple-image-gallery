<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Spec as OAS;

/** @mixin Tag */
#[OAS\Schema(component: 'Tag', description: 'A tag.', properties: [
    new OAS\Property(property: 'tag_id', schema: new OAS\Schema(type: 'integer')),
    new OAS\Property(property: 'category_id', schema: new OAS\Schema(type: 'integer')),
    new OAS\Property(property: 'tag_name', schema: new OAS\Schema(type: 'string')),
])]
final class TagResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'tag_id' => $this->tag_id,
            'category_id' => $this->category_id,
            'tag_name' => $this->tag_name,
        ];
    }
}
