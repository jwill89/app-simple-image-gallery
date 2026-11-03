<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ProcessNewMedia;
use App\Models\Media;
use App\Services\Danbooru\DanbooruTagger;
use App\Support\CacheGroup;
use App\Support\ResponseCache;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * The nightly ingest (routes/console.php). Files dropped into the media
 * folder's root are queued to be added, one ProcessNewMedia job each; items
 * whose file is gone from media/full/ are removed with their thumbnails and
 * tag links. Files of other types are left where they are.
 */
#[Description('Queue the files dropped into the media folder to be added, and remove items whose files are gone')]
#[Signature('gallery:ingest
        {--import-tags : Also import Danbooru tags for each new image}')]
final class IngestMedia extends Command
{
    public function handle(ResponseCache $cache): int
    {
        $importTags = (bool) $this->option('import-tags');
        if ($importTags && !DanbooruTagger::isConfigured()) {
            $this->error('Danbooru credentials are not configured: set DANBOORU_LOGIN and DANBOORU_API_KEY.');

            return self::FAILURE;
        }

        $removed = 0;
        foreach (Media::query()->select(['media_id', 'file_name'])->lazyById() as $media) {
            if (!File::isFile($media->fullPath())) {
                $media->delete();
                $removed++;
            }
        }
        if ($removed > 0) {
            $cache->bump(CacheGroup::Media, CacheGroup::Tags);
        }

        $queued = 0;
        $unsupported = 0;
        foreach (File::files(config()->string('gallery.media_path')) as $file) {
            if (Media::isSupportedFile($file->getFilename())) {
                ProcessNewMedia::dispatch($file->getFilename(), $importTags);
                $queued++;
            } else {
                $unsupported++;
            }
        }

        $this->info("Removed {$removed} item(s) whose files are gone.");
        $this->info("Queued {$queued} new file(s); left {$unsupported} of other types in place.");

        return self::SUCCESS;
    }
}
