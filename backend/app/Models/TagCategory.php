<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TagCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group of tags (General, Artist, …) with a display colour from the tag
 * palette, never a semantic state colour, and a sort order.
 */
#[Table(name: 'tag_categories', key: 'category_id', timestamps: false)]
class TagCategory extends Model
{
    /** @use HasFactory<TagCategoryFactory> */
    use HasFactory;

    /** @return HasMany<Tag, $this> */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class, 'category_id', 'category_id');
    }

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }
}
