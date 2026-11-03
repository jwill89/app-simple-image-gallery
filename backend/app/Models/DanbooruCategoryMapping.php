<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which gallery category a Danbooru tag category imports into. Keyed by
 * Danbooru's own category id; its name gives the post field read
 * (tag_string_<name>).
 */
#[Table(name: 'danbooru_category_map', key: 'danbooru_category_id', incrementing: false, timestamps: false)]
#[Fillable(['danbooru_category_id', 'danbooru_category_name', 'gallery_category_id'])]
class DanbooruCategoryMapping extends Model
{
    /** @return BelongsTo<TagCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(TagCategory::class, 'gallery_category_id', 'category_id');
    }

    protected function casts(): array
    {
        return ['gallery_category_id' => 'integer'];
    }
}
