<?php

declare(strict_types=1);

namespace App\Services\Media;

use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\RuntimeException;

/**
 * A media file's width, height, duration and size, as the media row stores
 * them. Images are read with getimagesize(); videos and animated GIFs with
 * ffprobe, falling back to getimagesize() (which knows GIFs) for the
 * dimensions when ffprobe isn't installed or can't read the file. Anything
 * unreadable is 0.
 */
final class MediaMetadata
{
    /** @return array{width: int, height: int, duration: float, file_size: int} */
    public function read(string $path, string $mediaType): array
    {
        $size = @filesize($path);
        $metadata = ['width' => 0, 'height' => 0, 'duration' => 0.0, 'file_size' => $size === false ? 0 : $size];

        $probe = $mediaType === 'video' ? $this->probe($path) : null;
        if ($probe !== null) {
            return [...$metadata, ...$probe];
        }

        $image = @getimagesize($path);

        return $image === false ? $metadata : [...$metadata, 'width' => $image[0], 'height' => $image[1]];
    }

    /** @return array{width: int, height: int, duration: float}|null */
    private function probe(string $path): ?array
    {
        try {
            $result = Process::run(['ffprobe', '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=width,height:format=duration', '-of', 'json', $path]);
        } catch (RuntimeException) {
            return null;
        }

        $data = json_decode($result->output(), true);
        if (!$result->successful() || !is_array($data)) {
            return null;
        }

        $stream = is_array($data['streams'][0] ?? null) ? $data['streams'][0] : [];
        $probe = [
            'width' => is_numeric($stream['width'] ?? null) ? (int) $stream['width'] : 0,
            'height' => is_numeric($stream['height'] ?? null) ? (int) $stream['height'] : 0,
            'duration' => is_array($data['format'] ?? null) && is_numeric($data['format']['duration'] ?? null) ? round((float) $data['format']['duration'], 2) : 0.0,
        ];

        return $probe === ['width' => 0, 'height' => 0, 'duration' => 0.0] ? null : $probe;
    }
}
