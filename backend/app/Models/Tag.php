<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A tag. Names are unique without regard to case (the column is COLLATE
 * NOCASE). Applying a tag also applies everything it implies, transitively.
 */
#[Table(name: 'tags', key: 'tag_id', timestamps: false)]
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    /** @return BelongsTo<TagCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(TagCategory::class, 'category_id', 'category_id');
    }

    /** @return BelongsToMany<Media, $this> */
    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'media_tags', 'tag_id', 'media_id');
    }

    /**
     * The tags this one implies directly.
     *
     * @return BelongsToMany<Tag, $this>
     */
    public function implies(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'tag_implications', 'tag_id', 'implied_tag_id');
    }

    /**
     * The tags that imply this one directly.
     *
     * @return BelongsToMany<Tag, $this>
     */
    public function impliedBy(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'tag_implications', 'implied_tag_id', 'tag_id');
    }

    protected function casts(): array
    {
        return ['category_id' => 'integer'];
    }
}
