<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ProcessNewMedia;
use App\Models\Media;
use App\Services\Danbooru\DanbooruTagger;
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
    public function handle(): int
    {
        $importTags = (bool) $this->option('import-tags');
        if ($importTags && !DanbooruTagger::isConfigured()) {
            $this->error('Danbooru credentials are not configured: set DANBOORU_LOGIN and DANBOORU_API_KEY.');

            return self::FAILURE;
        }

        // A missing full/ folder, or one where no item's file is, means the media
        // path is wrong (or a disk isn't mounted), not that everything was
        // deleted: removing the items would empty the gallery.
        $folder = config()->string('gallery.media_path');
        if (!File::isDirectory("{$folder}/full")) {
            $this->error("{$folder}/full is not a folder. Check GALLERY_MEDIA_PATH; nothing was changed.");

            return self::FAILURE;
        }
        $gone = Media::query()->select(['media_id', 'file_name'])->lazyById()
            ->reject(static fn(Media $media): bool => File::isFile($media->fullPath()))
            ->collect();
        if ($gone->isNotEmpty() && $gone->count() === Media::query()->count()) {
            $this->error("None of the {$gone->count()} items' files are in {$folder}/full. Check GALLERY_MEDIA_PATH; nothing was changed.");

            return self::FAILURE;
        }

        // Each delete clears the cached lists (the Media model), and takes the files.
        foreach ($gone as $media) {
            $media->delete();
        }

        $queued = 0;
        $unsupported = 0;
        foreach (File::files($folder) as $file) {
            if (Media::isSupportedFile($file->getFilename())) {
                ProcessNewMedia::dispatch($file->getFilename(), $importTags);
                $queued++;
            } else {
                $unsupported++;
            }
        }

        $this->info("Removed {$gone->count()} item(s) whose files are gone.");
        $this->info("Queued {$queued} new file(s); left {$unsupported} of other types in place.");

        return self::SUCCESS;
    }
}
