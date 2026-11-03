<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * An image or a video (animated GIFs count as video). Its file lives under
 * media/full/ and its thumbnails under media/thumbs/; width, height, duration
 * and file_size are read from the file at ingest.
 */
#[Table(name: 'media', key: 'media_id', timestamps: false)]
class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory;

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'media_tags', 'media_id', 'tag_id');
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
