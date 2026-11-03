<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ImportDanbooruTags;
use App\Models\Media;
use App\Services\Danbooru\DanbooruTagger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * A bulk Danbooru tag import: one ImportDanbooruTags job per media item. The
 * jobs are rate-limited on the queue, and each one logs what it found.
 */
#[Description('Queue a Danbooru tag import for every media item, or for one')]
#[Signature('gallery:import-tags
        {media? : Import only this media item, by id}')]
final class ImportTags extends Command
{
    public function handle(): int
    {
        if (!DanbooruTagger::isConfigured()) {
            $this->error('Danbooru credentials are not configured: set DANBOORU_LOGIN and DANBOORU_API_KEY.');

            return self::FAILURE;
        }

        $id = $this->argument('media');
        if ($id !== null) {
            $media = is_string($id) && ctype_digit($id) ? Media::query()->find((int) $id) : null;
            if ($media === null) {
                $this->error("Media #{$id} is not in the gallery.");

                return self::FAILURE;
            }
            ImportDanbooruTags::dispatch($media);
            $this->info("Queued a Danbooru import for media #{$media->media_id}.");

            return self::SUCCESS;
        }

        $queued = 0;
        foreach (Media::query()->lazyById() as $media) {
            ImportDanbooruTags::dispatch($media);
            $queued++;
        }
        $this->info("Queued {$queued} Danbooru import(s). They run on the queue, rate-limited, and log each result.");

        return self::SUCCESS;
    }
}
