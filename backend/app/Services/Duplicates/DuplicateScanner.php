<?php

declare(strict_types=1);

namespace App\Services\Duplicates;

use App\Models\DismissedDuplicate;
use App\Models\Media;
use GdImage;
use Illuminate\Support\Facades\File;
use Intervention\ImageHash\Hash;
use Throwable;

/**
 * Finds duplicate images by their perceptual fingerprints.
 *
 * Locality-sensitive hashing (LSH) finds candidate pairs, a Hamming-distance
 * cutoff keeps the close ones, and a comparison of each remaining pair's @2x
 * thumbnails rejects variants of one artwork that differ only in a small area
 * (RegionDifference). SSIM is reported alongside. Videos have no fingerprint,
 * so only images are compared.
 *
 * Thumbnails are read from config('gallery.media_path')/thumbs, and the report
 * is written to config('gallery.dupes_path').
 */
final class DuplicateScanner
{
    /** LSH buckets larger than this are skipped rather than paired up O(n^2). */
    private const int MAX_BUCKET_SIZE = 100;

    /**
     * Widest LSH band that still guarantees every pair within $maxDistance
     * shares at least one: d differing bits can spoil at most d bands, so
     * $maxDistance + 1 bands always leave one intact. Wider bands mean smaller
     * buckets, so fewer spurious candidates and fewer oversized buckets skipped.
     */
    public static function bandBitsFor(int $hashBits, int $maxDistance): int
    {
        return max(1, intdiv($hashBits, $maxDistance + 1));
    }

    /**
     * Scan the image media and write the report.
     *
     * Each match is [id1, id2, distance, ssim|null, region difference|null],
     * plus a dismissed flag on a tuning run. The null measures are pairs whose
     * thumbnails couldn't be read; they're kept, unjudged.
     *
     * @return array{
     *     generated_at: string,
     *     settings: array{max_distance: int, max_region_diff: ?float, band_bits: int, tuning: bool},
     *     images_compared: int,
     *     lsh_candidates: int,
     *     skipped_buckets: int,
     *     region_rejected: int,
     *     duplicates_found: int,
     *     execution_time_seconds: float,
     *     matches: list<array{0: int, 1: int, 2: int, 3: ?float, 4: ?float, 5?: bool}>,
     *     report_file: string,
     * }
     */
    public function run(ScanSettings $settings): array
    {
        // A heavy batch operation: raise the limits for the scan, then put them back.
        $memoryLimit = ini_get('memory_limit');
        $timeLimit = (int) ini_get('max_execution_time');
        ini_set('memory_limit', '512M');
        set_time_limit(300);

        try {
            return $this->scan($settings);
        } finally {
            ini_set('memory_limit', $memoryLimit);
            set_time_limit($timeLimit);
        }
    }

    /**
     * @return array{
     *     generated_at: string,
     *     settings: array{max_distance: int, max_region_diff: ?float, band_bits: int, tuning: bool},
     *     images_compared: int,
     *     lsh_candidates: int,
     *     skipped_buckets: int,
     *     region_rejected: int,
     *     duplicates_found: int,
     *     execution_time_seconds: float,
     *     matches: list<array{0: int, 1: int, 2: int, 3: ?float, 4: ?float, 5?: bool}>,
     *     report_file: string,
     * }
     */
    private function scan(ScanSettings $settings): array
    {
        $startTime = microtime(true);
        $dismissed = DismissedDuplicate::pairKeys();

        // Only the fields the scan needs, streamed rather than hydrated as models.
        $bitStrings = [];   // media_id => bit string
        $fileNames = [];    // media_id => file_name
        $rows = Media::query()->toBase()
            ->select(['media_id', 'file_name', 'bits_fingerprint'])
            ->where('media_type', 'image')
            ->where('bits_fingerprint', '!=', '')
            ->orderBy('media_id')
            ->cursor();
        foreach ($rows as $row) {
            $bitStrings[(int) $row->media_id] = (string) $row->bits_fingerprint;
            $fileNames[(int) $row->media_id] = (string) $row->file_name;
        }

        $imageCount = count($bitStrings);
        $sampleBits = reset($bitStrings);
        $bandBits = self::bandBitsFor($sampleBits === false ? 64 : strlen($sampleBits), $settings->maxDistance);

        // Phase 1: LSH. Band the fingerprints to find candidate pairs.
        ['pairs' => $candidates, 'skipped_buckets' => $skippedBuckets] = $this->lshCandidates($bitStrings, $bandBits);
        $candidateCount = count($candidates);

        // Phase 2: the Hamming-distance cutoff, building hashes only for ids that are candidates.
        $hashes = [];
        foreach ($candidates as [$id1, $id2]) {
            foreach ([$id1, $id2] as $id) {
                if (!isset($hashes[$id])) {
                    try {
                        $hashes[$id] = Hash::fromBits($bitStrings[$id]);
                    } catch (Throwable) {
                        // An unreadable fingerprint leaves the id out of every pair.
                    }
                }
            }
        }
        unset($bitStrings); // free the big array before the thumbnail phase

        $verified = [];
        foreach ($candidates as [$id1, $id2]) {
            // Dismissed pairs are skipped, except on a tuning run, which keeps
            // them as known non-duplicates.
            $isDismissed = isset($dismissed[DismissedDuplicate::pairKey($id1, $id2)]);
            if (($isDismissed && !$settings->tuning) || !isset($hashes[$id1], $hashes[$id2])) {
                continue;
            }

            $distance = $hashes[$id1]->distance($hashes[$id2]);
            if ($distance <= $settings->maxDistance) {
                $verified[] = [$id1, $id2, $distance, $isDismissed];
            }
        }
        unset($candidates, $hashes, $dismissed);

        // Phase 3: compare each remaining pair's @2x thumbnails (small WebP files,
        // rather than full-size originals, to avoid GD memory spikes).
        $thumbDirectory = config()->string('gallery.media_path') . '/thumbs/';
        $matches = [];
        $regionRejected = 0;

        foreach ($verified as [$id1, $id2, $distance, $isDismissed]) {
            ['ssim' => $ssim, 'region_diff' => $regionDiff] = $this->compareThumbnails(
                $thumbDirectory,
                $fileNames[$id1] ?? null,
                $fileNames[$id2] ?? null,
            );

            // A pair whose thumbnails couldn't be read is kept: the check rejects
            // what it has evidence against, not what it can't see.
            if ($settings->maxRegionDiff !== null && $regionDiff !== null && $regionDiff > $settings->maxRegionDiff) {
                $regionRejected++;
                continue;
            }

            $matches[] = $settings->tuning
                ? [$id1, $id2, $distance, $ssim, $regionDiff, $isDismissed]
                : [$id1, $id2, $distance, $ssim, $regionDiff];
        }

        // Most alike first: smallest region difference, then smallest distance.
        // Pairs that couldn't be compared go last.
        usort($matches, static fn(array $a, array $b): int => [$a[4] === null, $a[4], $a[2]] <=> [$b[4] === null, $b[4], $b[2]]);

        $result = [
            'generated_at' => now()->format('Y-m-d H:i:s'),
            'settings' => [
                'max_distance' => $settings->maxDistance,
                'max_region_diff' => $settings->maxRegionDiff,
                'band_bits' => $bandBits,
                'tuning' => $settings->tuning,
            ],
            'images_compared' => $imageCount,
            'lsh_candidates' => $candidateCount,
            'skipped_buckets' => $skippedBuckets,
            'region_rejected' => $regionRejected,
            'duplicates_found' => count($matches),
            'execution_time_seconds' => round(microtime(true) - $startTime, 2),
            'matches' => $matches,
        ];

        $result['report_file'] = $this->saveReport($result, $settings->tuning);

        return $result;
    }

    /**
     * LSH banding: split each fingerprint into bands of $bandBits bits, and
     * bucket each band. Pairs that share at least one bucket are candidates,
     * which takes the comparison from O(n^2) to roughly O(n * bucket size).
     *
     * @param array<int, string> $bitStrings media_id => bit string
     * @return array{pairs: list<array{int, int}>, skipped_buckets: int} Unique
     *         candidate pairs [id1, id2] where id1 < id2, and how many oversized
     *         buckets were skipped (each one can hide pairs within the distance)
     */
    private function lshCandidates(array $bitStrings, int $bandBits): array
    {
        $sampleBits = reset($bitStrings);
        if ($sampleBits === false) {
            return ['pairs' => [], 'skipped_buckets' => 0];
        }
        $bandCount = intdiv(strlen($sampleBits), $bandBits);
        $skippedBuckets = 0;

        // One band at a time, so the full bucket structure is never in memory.
        $seen = [];
        for ($band = 0; $band < $bandCount; $band++) {
            $offset = $band * $bandBits;

            $buckets = [];
            foreach ($bitStrings as $mediaId => $bits) {
                $buckets[substr($bits, $offset, $bandBits)][] = $mediaId;
            }

            // An oversized bucket is a common segment and would generate O(n^2)
            // spurious candidates.
            foreach ($buckets as $ids) {
                $count = count($ids);
                if ($count > self::MAX_BUCKET_SIZE) {
                    $skippedBuckets++;
                    continue;
                }

                for ($i = 0; $i < $count; $i++) {
                    for ($j = $i + 1; $j < $count; $j++) {
                        $a = min($ids[$i], $ids[$j]);
                        $b = max($ids[$i], $ids[$j]);
                        $seen[($a << 32) | $b] = true; // an integer key is cheaper than a string
                    }
                }
            }
        }

        $candidates = [];
        foreach (array_keys($seen) as $packed) {
            $candidates[] = [$packed >> 32, $packed & 0xFFFFFFFF];
        }

        return ['pairs' => $candidates, 'skipped_buckets' => $skippedBuckets];
    }

    /**
     * SSIM and region difference of two media items' @2x thumbnails, both null
     * when either thumbnail is missing or can't be decoded.
     *
     * @return array{ssim: ?float, region_diff: ?float}
     */
    private function compareThumbnails(string $thumbDirectory, ?string $fileName1, ?string $fileName2): array
    {
        $unjudged = ['ssim' => null, 'region_diff' => null];
        if ($fileName1 === null || $fileName2 === null) {
            return $unjudged;
        }

        try {
            $img1 = $this->loadImage($thumbDirectory . pathinfo($fileName1, PATHINFO_FILENAME) . '@2x.webp');
            $img2 = $this->loadImage($thumbDirectory . pathinfo($fileName2, PATHINFO_FILENAME) . '@2x.webp');
            if ($img1 === null || $img2 === null) {
                return $unjudged;
            }

            return [
                'ssim' => $this->ssimBetween($img1, $img2),
                'region_diff' => RegionDifference::between($img1, $img2),
            ];
        } catch (Throwable) {
            return $unjudged;
        }
    }

    /**
     * SSIM between two images: the luminance channels compared at a reduced
     * resolution, between 0.0 (totally different) and 1.0 (identical).
     */
    private function ssimBetween(GdImage $img1, GdImage $img2): float
    {
        $size = 64;
        $r1 = imagecreatetruecolor($size, $size);
        $r2 = imagecreatetruecolor($size, $size);

        imagecopyresampled($r1, $img1, 0, 0, 0, 0, $size, $size, imagesx($img1), imagesy($img1));
        imagecopyresampled($r2, $img2, 0, 0, 0, 0, $size, $size, imagesx($img2), imagesy($img2));

        return $this->ssimFromLuminance($this->luminance($r1, $size), $this->luminance($r2, $size));
    }

    private function loadImage(string $path): ?GdImage
    {
        $info = @getimagesize($path);
        if ($info === false) {
            return null;
        }

        $image = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_GIF => @imagecreatefromgif($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            IMAGETYPE_BMP => @imagecreatefrombmp($path),
            IMAGETYPE_AVIF => function_exists('imagecreatefromavif') ? @imagecreatefromavif($path) : null,
            default => null,
        };

        return $image instanceof GdImage ? $image : null;
    }

    /**
     * The luminance (ITU-R BT.601) of a square GD image, row by row, 0-255.
     *
     * @return list<float>
     */
    private function luminance(GdImage $image, int $size): array
    {
        $luminance = [];
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $rgb = (int) imagecolorat($image, $x, $y);
                $luminance[] = 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
            }
        }

        return $luminance;
    }

    /**
     * SSIM from two luminance arrays, over 8x8 windows with the standard
     * formula: C1 = (0.01 * 255)^2, C2 = (0.03 * 255)^2.
     *
     * @param list<float> $lum1
     * @param list<float> $lum2
     */
    private function ssimFromLuminance(array $lum1, array $lum2): float
    {
        $size = (int) sqrt(count($lum1));
        $windowSize = 8;
        $c1 = 6.5025;
        $c2 = 58.5225;

        $ssimSum = 0.0;
        $windowCount = 0;

        for ($y = 0; $y <= $size - $windowSize; $y += $windowSize) {
            for ($x = 0; $x <= $size - $windowSize; $x += $windowSize) {
                $w1 = [];
                $w2 = [];
                for ($wy = 0; $wy < $windowSize; $wy++) {
                    for ($wx = 0; $wx < $windowSize; $wx++) {
                        $index = ($y + $wy) * $size + ($x + $wx);
                        $w1[] = $lum1[$index];
                        $w2[] = $lum2[$index];
                    }
                }

                $n = count($w1);
                $mu1 = array_sum($w1) / $n;
                $mu2 = array_sum($w2) / $n;

                $sigma1Sq = 0.0;
                $sigma2Sq = 0.0;
                $sigma12 = 0.0;
                for ($i = 0; $i < $n; $i++) {
                    $d1 = $w1[$i] - $mu1;
                    $d2 = $w2[$i] - $mu2;
                    $sigma1Sq += $d1 * $d1;
                    $sigma2Sq += $d2 * $d2;
                    $sigma12 += $d1 * $d2;
                }
                $sigma1Sq /= $n;
                $sigma2Sq /= $n;
                $sigma12 /= $n;

                $numerator = (2.0 * $mu1 * $mu2 + $c1) * (2.0 * $sigma12 + $c2);
                $denominator = ($mu1 * $mu1 + $mu2 * $mu2 + $c1) * ($sigma1Sq + $sigma2Sq + $c2);

                $ssimSum += $numerator / $denominator;
                $windowCount++;
            }
        }

        return $windowCount > 0 ? round($ssimSum / $windowCount, 4) : 0.0;
    }

    /**
     * Write the report, as dupes-<date>.json, the file the Duplicates page reads,
     * or tuning-<timestamp>.json for a tuning run, which must not replace it.
     *
     * @param array<string, mixed> $result
     * @return string The path written.
     */
    private function saveReport(array $result, bool $tuning): string
    {
        $directory = config()->string('gallery.dupes_path');
        File::ensureDirectoryExists($directory);

        $path = $directory . '/' . ($tuning
            ? 'tuning-' . now()->format('Y-m-d-His') . '.json'
            : 'dupes-' . now()->format('Y-m-d') . '.json');
        File::put($path, json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return $path;
    }
}
