<?php

namespace Gallery\Core;

/**
 * MediaThumbnail class
 *
 * Centralizes all thumbnail generation using FFmpeg.
 * Produces 1x (200px) and 2x (400px) WebP thumbnails for any media type.
 *
 * Two strategies:
 *  - createFromImage(): Extracts the first frame. Works uniformly for static images
 *    (JPEG, PNG), animated formats (GIF, animated WebP), and any other image input.
 *  - createFromVideo(): Uses the FFmpeg 'thumbnail' filter for intelligent frame
 *    selection, with a first-frame fallback for very short videos.
 */
class MediaThumbnail
{
    /** @var array{max: int, suffix: string}[] Thumbnail size definitions */
    private const array SIZES = [
        ['max' => 200, 'suffix' => ''],
        ['max' => 400, 'suffix' => '@2x'],
    ];

    private const int QUALITY = 80;

    /**
     * Create WebP thumbnails from any image file (JPEG, PNG, GIF, WebP, BMP, etc.).
     * Extracts the first frame via FFmpeg, which handles both static and animated inputs.
     *
     * @param string $sourcePath Full path to the source image file.
     * @param string $thumbDir   Directory to write thumbnails into (with trailing slash).
     * @param string $baseName   Filename stem (without extension) for the output files.
     */
    public static function createFromImage(string $sourcePath, string $thumbDir, string $baseName): void
    {
        $nullDev = self::nullDevice();

        foreach (self::SIZES as $size) {
            $thumbnailPath = $thumbDir . $baseName . $size['suffix'] . '.webp';
            $scale = self::scaleFilter($size['max']);

            $cmd = sprintf(
                'ffmpeg -i %s -vf "%s" -frames:v 1 -c:v libwebp -q:v %d -y %s 2>%s',
                escapeshellarg($sourcePath),
                $scale,
                self::QUALITY,
                escapeshellarg($thumbnailPath),
                $nullDev
            );

            exec($cmd);
        }
    }

    /**
     * Create WebP thumbnails from a video file.
     * Uses the FFmpeg 'thumbnail' filter to intelligently select a representative frame,
     * with a first-frame fallback for very short videos where the thumbnail filter fails.
     *
     * @param string $sourcePath Full path to the source video file.
     * @param string $thumbDir   Directory to write thumbnails into (with trailing slash).
     * @param string $baseName   Filename stem (without extension) for the output files.
     */
    public static function createFromVideo(string $sourcePath, string $thumbDir, string $baseName): void
    {
        $nullDev = self::nullDevice();

        foreach (self::SIZES as $size) {
            $thumbnailPath = $thumbDir . $baseName . $size['suffix'] . '.webp';
            $scale = self::scaleFilter($size['max']);

            // Try smart frame selection with the 'thumbnail' filter
            $cmd = sprintf(
                'ffmpeg -i %s -vf "thumbnail,%s" -frames:v 1 -c:v libwebp -q:v %d -y %s 2>%s',
                escapeshellarg($sourcePath),
                $scale,
                self::QUALITY,
                escapeshellarg($thumbnailPath),
                $nullDev
            );

            exec($cmd, $output, $returnCode);

            // Fallback: if thumbnail filter fails (e.g. very short video), grab first frame
            if ($returnCode !== 0 || !file_exists($thumbnailPath)) {
                $cmd = sprintf(
                    'ffmpeg -i %s -vf "%s" -frames:v 1 -c:v libwebp -q:v %d -y %s 2>%s',
                    escapeshellarg($sourcePath),
                    $scale,
                    self::QUALITY,
                    escapeshellarg($thumbnailPath),
                    $nullDev
                );
                exec($cmd);
            }
        }
    }

    /**
     * Build the FFmpeg lanczos scale filter string for a given max dimension.
     *
     * @param int $max Maximum width or height in pixels.
     *
     * @return string The FFmpeg scale filter expression.
     */
    private static function scaleFilter(int $max): string
    {
        return "scale='min({$max},iw)':'min({$max},ih)':flags=lanczos:force_original_aspect_ratio=decrease";
    }

    /**
     * Return the platform-appropriate null device for stderr suppression.
     */
    private static function nullDevice(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    }
}
