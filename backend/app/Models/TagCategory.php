<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\CacheGroup;
use App\Support\ResponseCache;
use Database\Factories\TagCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group of tags (General, Artist, …) with a display colour from the tag
 * palette, never a semantic state colour, and a sort order.
 */
#[Table(name: 'tag_categories', key: 'category_id', timestamps: false)]
#[Fillable(['category_name', 'color', 'description', 'sort_order'])]
class TagCategory extends Model
{
    /** @use HasFactory<TagCategoryFactory> */
    use HasFactory;

    /**
     * Colours a tag category may use.
     *
     * Hue names only. These are display values and must never overlap the
     * semantic palette (danger / success / warning / info), which is reserved
     * for state in the UI. Categories previously stored Bulma state names —
     * Artist was `danger` — which made the Artist chip and the delete button
     * the same red. Migration 20260805000000 rewrote the stored values.
     *
     * Keep in sync with `VALID_COLORS` in frontend/src/constants/categories.ts.
     */
    public const array COLORS = ['neutral', 'rose', 'amber', 'emerald', 'sky', 'violet', 'teal', 'orange', 'lime'];

    /** @return HasMany<Tag, $this> */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class, 'category_id', 'category_id');
    }

    protected static function booted(): void
    {
        // A category's name and order show in the tag lists.
        $changed = static function (): void {
            app(ResponseCache::class)->bump(CacheGroup::Tags);
        };
        static::saved($changed);
        static::deleted($changed);
    }

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }
}
