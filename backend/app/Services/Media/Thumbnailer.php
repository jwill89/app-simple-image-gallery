<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\Media;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\RuntimeException;

/**
 * A media item's WebP thumbnails, fitted within 200px and 400px (the @2x),
 * made with ffmpeg. An image's or GIF's comes from its first frame; a video's
 * from a representative frame (ffmpeg's thumbnail filter), or its first frame
 * when that fails on a very short video. Without ffmpeg there are none, and
 * the gallery shows its placeholder.
 */
final class Thumbnailer
{
    /** The longest side of each thumbnail, in the order of Media::thumbnailPaths(). */
    private const array SIZES = [200, 400];

    private const int QUALITY = 80;

    /** Make the thumbnails of $media from its file at $sourcePath, replacing any it has. */
    public function create(Media $media, string $sourcePath): void
    {
        $fromVideo = $media->media_type === 'video' && strtolower(pathinfo($media->file_name, PATHINFO_EXTENSION)) !== 'gif';

        foreach (array_combine($media->thumbnailPaths(), self::SIZES) as $thumbnail => $size) {
            File::ensureDirectoryExists(dirname($thumbnail));
            $scale = "scale='min({$size},iw)':'min({$size},ih)':flags=lanczos:force_original_aspect_ratio=decrease";

            if (!$fromVideo || !$this->ffmpeg($sourcePath, "thumbnail,{$scale}", $thumbnail)) {
                $this->ffmpeg($sourcePath, $scale, $thumbnail);
            }
        }
    }

    /** Write the first frame of $filter's output as $output; true when that worked. */
    private function ffmpeg(string $source, string $filter, string $output): bool
    {
        try {
            $result = Process::run(['ffmpeg', '-i', $source, '-vf', $filter, '-frames:v', '1', '-c:v', 'libwebp', '-q:v', (string) self::QUALITY, '-y', $output]);
        } catch (RuntimeException) {
            return false;
        }

        return $result->successful() && File::exists($output);
    }
}
