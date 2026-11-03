<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Media;
use App\Services\Fingerprinter;
use App\Services\Media\MediaDerivative;
use App\Services\Media\MediaMetadata;
use App\Services\Media\Thumbnailer;
use App\Support\CacheGroup;
use App\Support\ResponseCache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rebuilds a media item's thumbnails, fingerprint or metadata from its file in
 * media/full/. An item whose file is missing is skipped, except for its
 * fingerprint, which is cleared, as is one that can't be computed: a stale
 * fingerprint would be compared with fresh ones, and a cleared one only takes
 * the image out of the duplicate scan. An item deleted before its turn is
 * dropped.
 *
 * ffmpeg can take minutes over a long video's thumbnail, hence the timeout
 * (queue.connections.database.retry_after allows for it).
 */
#[DeleteWhenMissingModels]
#[Timeout(300)]
final class RebuildFromFile implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Media $media, public readonly MediaDerivative $derivative) {}

    public function handle(Thumbnailer $thumbnailer, Fingerprinter $fingerprinter, MediaMetadata $metadata, ResponseCache $cache): void
    {
        $path = $this->media->fullPath();
        $exists = File::isFile($path);
        if (!$exists && $this->derivative !== MediaDerivative::Fingerprints) {
            Log::warning('Rebuild skipped an item whose file is missing', ['media_id' => $this->media->media_id, 'rebuild' => $this->derivative->value]);

            return;
        }

        if ($this->derivative === MediaDerivative::Thumbnails) {
            $thumbnailer->create($this->media, $path);

            return;
        }

        $this->media->update($this->derivative === MediaDerivative::Fingerprints
            ? ['bits_fingerprint' => $exists ? $this->fingerprint($fingerprinter, $path) : '']
            : $metadata->read($path, $this->media->media_type));

        // Unlike thumbnails, both are in the media items the API returns.
        $cache->bump(CacheGroup::Media);
    }

    private function fingerprint(Fingerprinter $fingerprinter, string $path): string
    {
        try {
            return $fingerprinter->fingerprint($path);
        } catch (Throwable $e) {
            Log::warning('Fingerprint could not be computed, so it was cleared', ['media_id' => $this->media->media_id, 'error' => $e->getMessage()]);

            return '';
        }
    }
}
