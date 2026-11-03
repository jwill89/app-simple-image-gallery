<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DanbooruCategoryMapping;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Danbooru category mapping with its gallery category's name (the
 * CategoryMapping schema).
 *
 * @mixin DanbooruCategoryMapping
 */
final class CategoryMappingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'danbooru_category_id' => $this->danbooru_category_id,
            'danbooru_category_name' => $this->danbooru_category_name,
            'gallery_category_id' => $this->gallery_category_id,
            'gallery_category_name' => $this->category?->category_name,
        ];
    }
}
