<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * An image or a video (animated GIFs count as video). Its file lives under
 * media/full/ and its thumbnails under media/thumbs/; width, height, duration
 * and file_size are read from the file at ingest.
 */
#[Table(name: 'media', key: 'media_id', timestamps: false)]
#[Fillable(['media_type', 'file_name', 'file_time', 'hash', 'bits_fingerprint', 'width', 'height', 'duration', 'file_size'])]
class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory;

    /** Extensions of still images. A GIF is one unless it is animated. */
    public const array IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'tif', 'avif'];

    /**
     * Whether a file is an image or a video, by its extension: an animated GIF
     * is a video, and so is anything that isn't an image.
     */
    public static function typeOf(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === 'gif') {
            return self::isAnimatedGif($path) ? 'video' : 'image';
        }

        return in_array($extension, self::IMAGE_EXTENSIONS, true) ? 'image' : 'video';
    }

    /**
     * A random item, or null when there are none.
     *
     * The threshold is picked once, by a scalar subquery, and the first row at
     * or after it is found by an index seek. `ORDER BY RANDOM()` would sort the
     * whole table, and `media_id >= ABS(RANDOM()) % MAX(media_id)` re-evaluates
     * RANDOM() per row, which scans and skews. RANDOM() is masked to 63 bits so
     * no ABS() can overflow. Rows after a gap left by a delete are slightly
     * more likely, which is fine for a gallery's "random".
     */
    public static function pickRandom(): ?self
    {
        return self::query()
            ->where('media_id', '>=', DB::raw('(SELECT (RANDOM() & 9223372036854775807) % MAX(media_id) FROM media)'))
            ->orderBy('media_id')
            ->first();
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'media_tags', 'media_id', 'tag_id');
    }

    /**
     * Its tags in display order: by their category's sort order, then name.
     *
     * @return Collection<int, Tag>
     */
    public function sortedTags(): Collection
    {
        return $this->tags()
            ->leftJoin('tag_categories', 'tag_categories.category_id', '=', 'tags.category_id')
            ->orderBy('tag_categories.sort_order')
            ->orderBy('tags.tag_name')
            ->get(['tags.*']);
    }

    /**
     * Apply these tags and every tag they imply. Tags it has already stay.
     *
     * @param list<int> $tagIds
     */
    public function addTags(array $tagIds): void
    {
        $this->tags()->syncWithoutDetaching(Tag::withImplied($tagIds));
    }

    /** The full-size file, served at /media/full/<file_name>. */
    public function fullPath(): string
    {
        return config()->string('gallery.media_path') . '/full/' . $this->file_name;
    }

    /**
     * The 200px and 400px WebP thumbnails, <base>.webp and <base>@2x.webp.
     *
     * @return list<string>
     */
    public function thumbnailPaths(): array
    {
        $base = config()->string('gallery.media_path') . '/thumbs/' . pathinfo($this->file_name, PATHINFO_FILENAME);

        return ["{$base}.webp", "{$base}@2x.webp"];
    }

    /**
     * Newest first, as every listing is: by ingest time, then id.
     *
     * @param Builder<self> $query
     */
    #[Scope]
    protected function newestFirst(Builder $query): void
    {
        $query->orderByDesc('file_time')->orderByDesc('media_id');
    }

    /**
     * Media carrying every tag in $include and none in $exclude: the tag
     * search. Either list may be empty.
     *
     * @param Builder<self> $query
     * @param list<int> $include
     * @param list<int> $exclude
     */
    #[Scope]
    protected function taggedWith(Builder $query, array $include, array $exclude = []): void
    {
        if ($include !== []) {
            // (media_id, tag_id) is media_tags' primary key, so a row per
            // included tag means media carrying all of them.
            $query->whereIn('media.media_id', DB::table('media_tags')
                ->select('media_id')
                ->whereIn('tag_id', $include)
                ->groupBy('media_id')
                ->havingRaw('COUNT(*) = ?', [count($include)]));
        }
        if ($exclude !== []) {
            $query->whereNotIn('media.media_id', DB::table('media_tags')->select('media_id')->whereIn('tag_id', $exclude));
        }
    }

    /** @param Builder<self> $query */
    #[Scope]
    protected function untagged(Builder $query): void
    {
        $query->whereNotExists(DB::table('media_tags')->whereColumn('media_tags.media_id', 'media.media_id'));
    }

    /**
     * More than one frame: a GIF has a Graphic Control Extension (0x21 0xF9
     * 0x04) per frame. Read in chunks, and only until a second one turns up.
     */
    private static function isAnimatedGif(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $frames = 0;
        while (!feof($handle) && $frames < 2) {
            $chunk = fread($handle, 524288);
            if ($chunk === false) {
                break;
            }
            $frames += substr_count($chunk, "\x21\xF9\x04");
        }
        fclose($handle);

        return $frames > 1;
    }

    protected static function booted(): void
    {
        // Its tag links go with the row (ON DELETE CASCADE); its files go here.
        static::deleted(static function (self $media): void {
            File::delete([$media->fullPath(), ...$media->thumbnailPaths()]);
        });
    }

    protected function casts(): array
    {
        return [
            'file_time' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration' => 'float',
            'file_size' => 'integer',
        ];
    }
}
