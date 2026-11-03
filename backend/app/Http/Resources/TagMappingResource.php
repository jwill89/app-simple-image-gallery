<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DanbooruTagMapping;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Danbooru tag-name mapping (the TagMapping schema).
 *
 * @mixin DanbooruTagMapping
 */
final class TagMappingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'danbooru_tag' => $this->danbooru_tag,
            'gallery_tag' => $this->gallery_tag,
        ];
    }
}
