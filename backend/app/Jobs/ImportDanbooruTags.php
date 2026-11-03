<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Media;
use App\Services\Danbooru\DanbooruFailure;
use App\Services\Danbooru\DanbooruTagger;
use App\Support\CacheGroup;
use App\Support\ResponseCache;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;

/**
 * Imports one media item's tags from Danbooru, as POST
 * /media/{id}/danbooru-tags does without a post id: by MD5, then IQDB.
 *
 * The "danbooru" rate limiter (AppServiceProvider) spaces these out, so a bulk
 * import can't hammer Danbooru; a job over the limit, or one Danbooru answers
 * 429, waits its turn again. That counts as an attempt, so these retry for a
 * day rather than the worker's --tries, and give up after three exceptions.
 * An item deleted before its turn has nothing to import, and is dropped.
 */
#[DeleteWhenMissingModels]
#[MaxExceptions(3)]
final class ImportDanbooruTags implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Media $media) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new RateLimited('danbooru')];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addDay();
    }

    public function handle(DanbooruTagger $tagger, ResponseCache $cache): void
    {
        $import = $tagger->importForMedia($this->media);

        if ($import->failure === DanbooruFailure::RateLimit) {
            $this->release(60);

            return;
        }

        if (!$import->found) {
            Log::info('Danbooru import found no post', ['media_id' => $this->media->media_id, 'reason' => $import->failure->name ?? 'not found']);

            return;
        }

        $cache->bump(CacheGroup::Media, CacheGroup::Tags);
        Log::info('Danbooru tags imported', [
            'media_id' => $this->media->media_id,
            'method' => $import->method,
            'tags_applied' => $import->tagsApplied,
            'tags_created' => $import->tagsCreated,
        ]);
    }
}
