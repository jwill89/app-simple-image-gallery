<?php

namespace Gallery\Core;

/**
 * MediaMetadata
 *
 * Extracts basic technical metadata from a media file at ingest time:
 *   - width / height (pixels)
 *   - duration (seconds; videos and animated GIFs only)
 *   - file_size (bytes)
 *
 * Images use PHP's getimagesize(). Videos (including animated GIFs, which the
 * gallery classifies as 'video') are probed with `ffprobe`, which ships with
 * ffmpeg — already a required dependency for thumbnail generation.
 */
class MediaMetadata
{
    /**
     * Extract metadata for a media file.
     *
     * @param string $path      Full path to the source file.
     * @param string $mediaType 'image' or 'video'.
     *
     * @return array{width: int, height: int, duration: float, file_size: int}
     */
    public static function extract(string $path, string $mediaType): array
    {
        $meta = ['width' => 0, 'height' => 0, 'duration' => 0.0, 'file_size' => 0];

        if (!is_file($path)) {
            return $meta;
        }

        $size = @filesize($path);
        if ($size !== false) {
            $meta['file_size'] = $size;
        }

        // Still images: dimensions via getimagesize(), no duration.
        if ($mediaType === 'image') {
            $info = @getimagesize($path);
            if ($info !== false) {
                $meta['width']  = $info[0];
                $meta['height'] = $info[1];
            }
            return $meta;
        }

        // Videos / animated GIFs: probe with ffprobe.
        $probe = self::ffprobe($path);
        if ($probe !== null) {
            $meta['width']    = $probe['width'];
            $meta['height']   = $probe['height'];
            $meta['duration'] = $probe['duration'];
        } else {
            // Fallback for dimensions if ffprobe is unavailable (e.g. GIF).
            $info = @getimagesize($path);
            if ($info !== false) {
                $meta['width']  = $info[0];
                $meta['height'] = $info[1];
            }
        }

        return $meta;
    }

    /**
     * Probe a video file for width, height, and duration via ffprobe.
     *
     * @return array{width: int, height: int, duration: float}|null Null on failure.
     */
    private static function ffprobe(string $path): ?array
    {
        $nullDev = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';

        $cmd = sprintf(
            'ffprobe -v error -select_streams v:0 -show_entries stream=width,height:format=duration -of json %s 2>%s',
            escapeshellarg($path),
            $nullDev
        );

        $output = shell_exec($cmd);
        if ($output === null || $output === false || $output === '') {
            return null;
        }

        $data = json_decode($output, true);
        if (!is_array($data)) {
            return null;
        }

        $stream   = $data['streams'][0] ?? [];
        $width    = (int) ($stream['width'] ?? 0);
        $height   = (int) ($stream['height'] ?? 0);
        $duration = round((float) ($data['format']['duration'] ?? 0), 2);

        if ($width === 0 && $height === 0 && $duration === 0.0) {
            return null;
        }

        return ['width' => $width, 'height' => $height, 'duration' => $duration];
    }
}
