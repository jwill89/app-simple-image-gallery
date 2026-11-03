<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Media;
use App\Services\Media\MediaIngest;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Adds one file from the ingest folder (the media folder's root) to the
 * gallery, then moves it into media/full/. A file whose name or content is in
 * the gallery already is deleted instead. Queued once per file name, so an
 * ingest run before the last one's jobs are done doesn't queue it twice.
 *
 * ffmpeg can take minutes over a long video's thumbnail, hence the timeout
 * (queue.connections.database.retry_after allows for it).
 */
#[Timeout(300)]
final class ProcessNewMedia implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $fileName,
        /** Queue a Danbooru tag import for the item when it is an image. */
        public readonly bool $importTags = false,
    ) {}

    public function uniqueId(): string
    {
        return $this->fileName;
    }

    public function handle(MediaIngest $ingest): void
    {
        $path = config()->string('gallery.media_path') . '/' . $this->fileName;
        if (!File::isFile($path)) {
            return;
        }

        // Even with other content: a name in the gallery is taken.
        if (Media::query()->where('file_name', $this->fileName)->exists()) {
            File::delete($path);
            Log::info('Ingest skipped a file whose name is in the gallery', ['file' => $this->fileName]);

            return;
        }

        $hash = md5_file($path);
        if ($hash === false) {
            Log::warning('Ingest could not read a file', ['file' => $this->fileName]);

            return;
        }
        if (Media::query()->where('hash', $hash)->exists()) {
            File::delete($path);
            Log::info('Ingest skipped a copy of an item in the gallery', ['file' => $this->fileName, 'hash' => $hash]);

            return;
        }

        // Added from where it is and moved after, so a file that fails to add
        // stays in the ingest folder for the next run.
        $media = $ingest->add($path, $this->fileName, $hash, File::lastModified($path));
        File::ensureDirectoryExists(dirname($media->fullPath()));
        File::move($path, $media->fullPath());

        Log::info('Media ingested', ['media_id' => $media->media_id, 'file' => $this->fileName]);

        if ($this->importTags && $media->media_type === 'image') {
            ImportDanbooruTags::dispatch($media);
        }
    }
}
