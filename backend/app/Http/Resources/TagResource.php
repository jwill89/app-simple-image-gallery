<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Spec as OAS;

/**
 * A tag; with its counts when the query loaded them (GET /tags?include=counts
 * does, as media_count and implication_count).
 *
 * @mixin Tag
 */
#[OAS\Schema(
    component: 'Tag',
    description: 'A tag.',
    required: ['tagId', 'categoryId', 'tagName'],
    properties: [
        new OAS\Property(property: 'tagId', schema: new OAS\Schema(type: 'integer', description: 'The tag\'s id.')),
        new OAS\Property(property: 'categoryId', schema: new OAS\Schema(type: 'integer', description: 'The tag category it belongs to.')),
        new OAS\Property(property: 'tagName', schema: new OAS\Schema(type: 'string', description: 'Lowercase; unique without regard to case.')),
        new OAS\Property(property: 'mediaCount', schema: new OAS\Schema(type: 'integer', description: 'How many media items carry it (GET /tags with include=counts only).')),
        new OAS\Property(property: 'implicationCount', schema: new OAS\Schema(type: 'integer', description: 'How many tags it implies directly (GET /tags with include=counts only).')),
    ],
)]
final class TagResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'tagId' => $this->tag_id,
            'categoryId' => $this->category_id,
            'tagName' => $this->tag_name,
            'mediaCount' => $this->whenHas('media_count', fn(): int => (int) $this->resource->getAttribute('media_count')),
            'implicationCount' => $this->whenHas('implication_count', fn(): int => (int) $this->resource->getAttribute('implication_count')),
        ];
    }
}
