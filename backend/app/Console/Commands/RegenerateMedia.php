<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RebuildFromFile;
use App\Models\Media;
use App\Services\Media\MediaDerivative;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Rebuilds the thumbnails, fingerprints or metadata of every media item from
 * its file, one RebuildFromFile job per item (fingerprints: per image).
 */
#[Description('Queue every media item\'s thumbnails, fingerprint or metadata to be rebuilt from its file')]
#[Signature('gallery:regenerate
        {derivative : What to rebuild: thumbnails, fingerprints or metadata}')]
final class RegenerateMedia extends Command
{
    public function handle(): int
    {
        $argument = $this->argument('derivative');
        $derivative = is_string($argument) ? MediaDerivative::tryFrom($argument) : null;
        if ($derivative === null) {
            $this->error('Say what to rebuild: thumbnails, fingerprints or metadata.');

            return self::INVALID;
        }

        // Thumbnails are WebP; any other file in the folder is a leftover and goes.
        if ($derivative === MediaDerivative::Thumbnails) {
            $directory = config()->string('gallery.media_path') . '/thumbs';
            $old = File::isDirectory($directory)
                ? array_filter(File::files($directory), static fn(\SplFileInfo $file): bool => strtolower($file->getExtension()) !== 'webp')
                : [];
            File::delete(array_map(static fn(\SplFileInfo $file): string => $file->getPathname(), $old));
            $this->info('Removed ' . count($old) . ' old thumbnail file(s).');
        }

        $query = Media::query();
        if ($derivative === MediaDerivative::Fingerprints) {
            $query->where('media_type', 'image');
        }

        $queued = 0;
        foreach ($query->lazyById() as $media) {
            RebuildFromFile::dispatch($media, $derivative);
            $queued++;
        }
        $this->info("Queued {$queued} item(s) to have their {$derivative->value} rebuilt.");

        return self::SUCCESS;
    }
}
