<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\CacheGroup;
use App\Support\ResponseCache;
use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

/**
 * A tag. Names are unique without regard to case (the column is COLLATE
 * NOCASE). Applying a tag also applies everything it implies, transitively.
 */
#[Table(name: 'tags', key: 'tag_id', timestamps: false)]
#[Fillable(['tag_name', 'category_id'])]
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    /**
     * A tag name as it is stored: trimmed, lowercase, without HTML or control
     * characters, and without a leading "-", which a search reads as an
     * exclusion.
     */
    public static function normalizeName(string $name): string
    {
        return $name
            |> trim(...)
            |> (static fn(string $s): string => mb_strtolower($s, 'UTF-8'))
            |> strip_tags(...)
            |> (static fn(string $s): string => (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $s))
            |> (static fn(string $s): string => ltrim($s, '-'));
    }

    /**
     * The ids of the tags with these names, without regard to case. A name
     * that isn't a tag is skipped.
     *
     * @param list<string> $names
     * @return list<int>
     */
    public static function idsNamed(array $names): array
    {
        if ($names === []) {
            return [];
        }

        return array_values(array_map(intval(...), self::query()->whereIn('tag_name', $names)->pluck('tag_id')->all()));
    }

    /**
     * These tags and every tag they imply, transitively, in one recursive
     * query. UNION skips tags already reached, which also ends any cycle.
     *
     * @param list<int> $tagIds
     * @return list<int>
     */
    public static function withImplied(array $tagIds): array
    {
        $seeds = array_values(array_unique($tagIds));
        if ($seeds === []) {
            return [];
        }

        $values = implode(',', array_fill(0, count($seeds), '(?)'));
        $rows = DB::select(
            "WITH RECURSIVE closure(id) AS (
                VALUES {$values}
                UNION
                SELECT ti.implied_tag_id FROM tag_implications ti JOIN closure c ON ti.tag_id = c.id
            )
            SELECT id FROM closure",
            $seeds,
        );

        return array_map(intval(...), array_column($rows, 'id'));
    }

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

    /**
     * Give this tag's media to $target, which keeps the media it already has,
     * and leave this tag on none. What $target implies is not applied.
     */
    public function moveMediaTo(self $target): void
    {
        DB::transaction(function () use ($target): void {
            DB::table('media_tags')->insertOrIgnoreUsing(
                ['media_id', 'tag_id'],
                DB::table('media_tags')->selectRaw('media_id, ?', [$target->tag_id])->where('tag_id', $this->tag_id),
            );
            $this->media()->detach();
        });
        Media::tagsChanged();
    }

    /** Make this tag imply $implied (once: one that exists already is no error). */
    public function imply(self $implied): void
    {
        $this->implies()->syncWithoutDetaching([$implied->tag_id]);
        app(ResponseCache::class)->bump(CacheGroup::Tags);
    }

    /** Stop this tag implying $implied. Whether it did is the caller's check. */
    public function unimply(self $implied): void
    {
        $this->implies()->detach($implied->tag_id);
        app(ResponseCache::class)->bump(CacheGroup::Tags);
    }

    /**
     * Tags whose name contains $term, best match first: the name itself, then
     * names starting with it, then the rest, each alphabetically.
     *
     * @param Builder<self> $query
     */
    #[Scope]
    protected function matching(Builder $query, string $term): void
    {
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
        $query->whereRaw("tags.tag_name LIKE ? ESCAPE '\\'", ["%{$like}%"])
            ->orderByRaw("CASE WHEN tags.tag_name = ? THEN 0 WHEN tags.tag_name LIKE ? ESCAPE '\\' THEN 1 ELSE 2 END", [$term, "{$like}%"])
            ->orderBy('tags.tag_name');
    }

    protected static function booted(): void
    {
        // A tag's name and category show in the tag lists. Deleting one also
        // takes its links (ON DELETE CASCADE), which the media listings show.
        static::saved(static function (): void {
            app(ResponseCache::class)->bump(CacheGroup::Tags);
        });
        static::deleted(static function (): void {
            app(ResponseCache::class)->bump(CacheGroup::Tags, CacheGroup::Media);
        });
    }

    protected function casts(): array
    {
        return ['category_id' => 'integer'];
    }
}
