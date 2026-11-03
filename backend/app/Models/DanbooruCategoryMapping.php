<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Which gallery category a Danbooru tag category imports into. Keyed by
 * Danbooru's own category id; its name gives the post field read
 * (tag_string_<name>).
 */
#[Table(name: 'danbooru_category_map', key: 'danbooru_category_id', incrementing: false, timestamps: false)]
class DanbooruCategoryMapping extends Model
{
    protected function casts(): array
    {
        return ['gallery_category_id' => 'integer'];
    }
}
