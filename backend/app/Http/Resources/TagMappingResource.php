<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DanbooruTagMapping;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Spec as OAS;

/**
 * A Danbooru tag-name mapping.
 *
 * @mixin DanbooruTagMapping
 */
#[OAS\Schema(
    component: 'TagMapping',
    description: 'A Danbooru tag name imported under another gallery tag name.',
    required: ['id', 'danbooruTag', 'galleryTag'],
    properties: [
        new OAS\Property(property: 'id', schema: new OAS\Schema(type: 'integer', description: 'The mapping\'s id.')),
        new OAS\Property(property: 'danbooruTag', schema: new OAS\Schema(type: 'string', description: 'The tag as Danbooru names it; one mapping each.')),
        new OAS\Property(property: 'galleryTag', schema: new OAS\Schema(type: 'string', description: 'The gallery tag name it imports as.')),
    ],
)]
final class TagMappingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'danbooruTag' => $this->danbooru_tag,
            'galleryTag' => $this->gallery_tag,
        ];
    }
}
