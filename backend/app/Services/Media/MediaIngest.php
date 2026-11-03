<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\Media;
use App\Services\Fingerprinter;

/**
 * Adds a file to the gallery: its media row, with the type, the perceptual
 * fingerprint (images only; duplicate detection compares them) and the
 * metadata read from the file, then its thumbnails.
 */
final readonly class MediaIngest
{
    public function __construct(
        private Fingerprinter $fingerprinter,
        private MediaMetadata $metadata,
        private Thumbnailer $thumbnailer,
    ) {}

    /** The file at $path, as the media item $fileName with this MD5. */
    public function add(string $path, string $fileName, string $hash): Media
    {
        $type = Media::typeOf($path);

        $media = Media::query()->create([
            'media_type' => $type,
            'file_name' => $fileName,
            'file_time' => now()->getTimestamp(),
            'hash' => $hash,
            'bits_fingerprint' => $type === 'image' ? $this->fingerprinter->fingerprint($path) : '',
            ...$this->metadata->read($path, $type),
        ]);
        $this->thumbnailer->create($media, $path);

        return $media;
    }
}
