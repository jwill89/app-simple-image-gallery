<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A tag with its category's name and its usage counts, for the tags page
 * (the TagListItem schema). The tag is loaded with category_name, media_count
 * and implication_count.
 *
 * @mixin Tag
 *
 * @property-read ?string $category_name
 * @property-read int $media_count
 * @property-read int $implication_count
 */
final class TagListItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'tag_id' => $this->tag_id,
            'tag_name' => $this->tag_name,
            'category_id' => $this->category_id,
            'category_name' => $this->category_name,
            'media_count' => $this->media_count,
            'implication_count' => $this->implication_count,
        ];
    }
}
