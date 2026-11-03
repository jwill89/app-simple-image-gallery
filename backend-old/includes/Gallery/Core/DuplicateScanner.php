<?php

namespace Gallery\Core;

use PDO;
use Intervention\ImageHash\Hash;
use Gallery\Collection\MediaCollection;

/**
 * DuplicateScanner class
 *
 * Scans the media database for duplicate images using perceptual hashing.
 * Uses Locality-Sensitive Hashing (LSH) for efficient candidate finding, a
 * Hamming-distance cutoff, then compares each remaining pair's thumbnails: the
 * region difference (RegionDifference) rejects variants of one artwork that
 * differ only in a small area. SSIM is still reported alongside it.
 * Only compares image-type media (videos don't have fingerprints).
 */
class DuplicateScanner
{
    /** LSH buckets larger than this are skipped rather than paired up O(n^2). */
    private const int MAX_BUCKET_SIZE = 100;

    public function __construct(
        private readonly PDO $db,
        /** Largest Hamming distance (bits out of 64) still reported. */
        private readonly int $maxDistance = Configuration::DEFAULT_DUPLICATE_MAX_DISTANCE,
        /** Largest region difference (0-255) still reported; null turns the check off. */
        private readonly ?float $maxRegionDiff = Configuration::DEFAULT_DUPLICATE_MAX_REGION_DIFF,
        private readonly string $reportDirectory = 'dupes/',
        /**
         * A tuning run keeps pairs the user has dismissed (flagged as such, as
         * known non-duplicates to calibrate against) and writes its report to
         * tuning-<timestamp>.json, leaving the dupes-*.json the UI reads alone.
         */
        private readonly bool $tuning = false,
        /** Where the @2x thumbnails the second pass compares live. */
        private readonly string $thumbDirectory = MediaCollection::MEDIA_DIRECTORY_THUMBNAILS
    ) {
    }

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
     * Run the duplicate scan on image media items.
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
    public function run(): array
    {
        // Temporarily raise limits for the scan — this is a heavy batch operation
        $previousMemoryLimit = ini_get('memory_limit');
        $previousTimeLimit = ini_get('max_execution_time');
        ini_set('memory_limit', '512M');
        set_time_limit(300); // 5 minutes

        $start_time = microtime(true);

        // Load dismissed pairs from DB
        $dismissed = $this->loadDismissedPairs();

        // ──────────────────────────────────────────────
        // Load only the fields we need — no full Media objects
        // ──────────────────────────────────────────────
        $db = $this->db;
        $stmt = $db->query(
            "SELECT media_id, file_name, bits_fingerprint
             FROM media
             WHERE media_type = 'image' AND bits_fingerprint != ''
             ORDER BY media_id"
        );

        $bitStrings = [];   // media_id => bit string
        $fileNames  = [];   // media_id => file_name

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $id   = (int) $row['media_id'];
            $bits = $row['bits_fingerprint'];

            if (!is_string($bits) || $bits === '') {
                continue;
            }

            $bitStrings[$id] = $bits;
            $fileNames[$id]  = $row['file_name'];
        }
        unset($stmt);

        $hash_count = count($bitStrings);
        $sampleBits = reset($bitStrings);
        $bandBits = self::bandBitsFor($sampleBits === false ? 64 : strlen($sampleBits), $this->maxDistance);

        // ──────────────────────────────────────────────
        // Phase 1: LSH — band the fingerprints to find candidate pairs
        // ──────────────────────────────────────────────
        ['pairs' => $candidates, 'skipped_buckets' => $skippedBuckets] = $this->lshCandidates($bitStrings, $bandBits);
        $lsh_candidate_count = count($candidates);

        // ──────────────────────────────────────────────
        // Phase 2: Hamming distance filter on candidates
        // Build Hash objects only for IDs that appear in candidates
        // ──────────────────────────────────────────────
        $neededIds = [];
        foreach ($candidates as [$id1, $id2]) {
            $neededIds[$id1] = true;
            $neededIds[$id2] = true;
        }

        $hashes = [];
        foreach ($neededIds as $id => $_) {
            if (isset($bitStrings[$id])) {
                try {
                    $hashes[$id] = Hash::fromBits($bitStrings[$id]);
                } catch (\Exception) {
                    // skip
                }
            }
        }
        unset($neededIds, $bitStrings); // free the big arrays

        $verified = [];

        foreach ($candidates as [$id1, $id2]) {
            // Skip dismissed pairs, except on a tuning run, which keeps them as
            // known non-duplicates
            $isDismissed = isset($dismissed[$this->pairKey($id1, $id2)]);
            if ($isDismissed && !$this->tuning) {
                continue;
            }

            if (!isset($hashes[$id1], $hashes[$id2])) {
                continue;
            }

            $distance = $hashes[$id1]->distance($hashes[$id2]);

            if ($distance <= $this->maxDistance) {
                $verified[] = [$id1, $id2, $distance, $isDismissed];
            }
        }
        unset($candidates, $hashes, $dismissed); // free before the thumbnail phase

        // ──────────────────────────────────────────────
        // Phase 3: compare the thumbnails of each pair left
        // Use @2x thumbnails (already small WebP files) instead of
        // full-size originals to avoid GD memory spikes.
        // ──────────────────────────────────────────────
        $matches = [];
        $regionRejected = 0;

        foreach ($verified as [$id1, $id2, $distance, $isDismissed]) {
            ['ssim' => $ssim, 'region_diff' => $regionDiff] = $this->compareThumbnails(
                $fileNames[$id1] ?? null,
                $fileNames[$id2] ?? null
            );

            // A pair whose thumbnails couldn't be read is kept: the check rejects
            // what it has evidence against, not what it can't see.
            if ($this->maxRegionDiff !== null && $regionDiff !== null && $regionDiff > $this->maxRegionDiff) {
                $regionRejected++;
                continue;
            }

            $matches[] = $this->tuning
                ? [$id1, $id2, $distance, $ssim, $regionDiff, $isDismissed]
                : [$id1, $id2, $distance, $ssim, $regionDiff];
        }
        unset($verified, $fileNames);

        // Most alike first: smallest region difference, then smallest distance.
        // Pairs that couldn't be compared go last.
        usort($matches, static fn(array $a, array $b): int => [$a[4] === null, $a[4], $a[2]] <=> [$b[4] === null, $b[4], $b[2]]);

        $end_time = microtime(true);
        $execution_time = round($end_time - $start_time, 2);

        $result = [
            'generated_at' => date('Y-m-d H:i:s'),
            'settings' => [
                'max_distance' => $this->maxDistance,
                'max_region_diff' => $this->maxRegionDiff,
                'band_bits' => $bandBits,
                'tuning' => $this->tuning,
            ],
            'images_compared' => $hash_count,
            'lsh_candidates' => $lsh_candidate_count,
            'skipped_buckets' => $skippedBuckets,
            'region_rejected' => $regionRejected,
            'duplicates_found' => count($matches),
            'execution_time_seconds' => $execution_time,
            'matches' => $matches,
        ];

        $result['report_file'] = $this->saveReport($result);

        // Restore original limits
        ini_set('memory_limit', $previousMemoryLimit);
        set_time_limit((int) $previousTimeLimit);

        return $result;
    }

    /**
     * LSH banding: split each fingerprint into bands of $bandBits bits,
     * and hash each band. Pairs that share at least one band bucket are candidates.
     *
     * For a 64-bit hash at the default max distance of 2, bandBitsFor() gives
     * 21-bit bands, 3 of them. This reduces comparison from O(n^2) to roughly
     * O(n * bucket_size).
     *
     * @param array<int, string> $bitStrings media_id => bit string
     * @return array{pairs: list<array{int, int}>, skipped_buckets: int} Unique
     *         candidate pairs [id1, id2] where id1 < id2, and how many oversized
     *         buckets were skipped (each one can hide pairs within the distance)
     */
    private function lshCandidates(array $bitStrings, int $bandBits): array
    {
        // Determine band count from the first fingerprint length
        $sampleBits = reset($bitStrings);
        if ($sampleBits === false) {
            return ['pairs' => [], 'skipped_buckets' => 0];
        }
        $totalBits = strlen($sampleBits);
        $numBands = intdiv($totalBits, $bandBits);
        $skippedBuckets = 0;

        // Collect candidate pairs directly — process one band at a time
        // to avoid keeping the full bucket structure in memory
        $seen = [];

        for ($band = 0; $band < $numBands; $band++) {
            $offset = $band * $bandBits;

            // Build buckets for this single band
            $buckets = [];
            foreach ($bitStrings as $mediaId => $bits) {
                $segment = substr($bits, $offset, $bandBits);
                $buckets[$segment][] = $mediaId;
            }

            // Extract pairs from this band's buckets
            // Skip oversized buckets — they indicate a common hash segment and
            // would generate O(n^2) spurious candidates
            foreach ($buckets as $ids) {
                $count = count($ids);
                if ($count > self::MAX_BUCKET_SIZE) {
                    $skippedBuckets++;
                    continue;
                }
                if ($count < 2) {
                    continue;
                }

                for ($i = 0; $i < $count; $i++) {
                    for ($j = $i + 1; $j < $count; $j++) {
                        $a = min($ids[$i], $ids[$j]);
                        $b = max($ids[$i], $ids[$j]);
                        $key = ($a << 32) | $b; // integer key — cheaper than string concat
                        $seen[$key] = ($a << 32) | $b;
                    }
                }
            }
            // $buckets for this band is freed here
        }

        // Convert to pair arrays
        $candidates = [];
        foreach ($seen as $packed) {
            $candidates[] = [$packed >> 32, $packed & 0xFFFFFFFF];
        }
        unset($seen);

        return ['pairs' => $candidates, 'skipped_buckets' => $skippedBuckets];
    }

    /**
     * SSIM and region difference of two media items' @2x thumbnails, both null
     * when either thumbnail is missing or can't be decoded.
     *
     * @return array{ssim: ?float, region_diff: ?float}
     */
    private function compareThumbnails(?string $fileName1, ?string $fileName2): array
    {
        $unjudged = ['ssim' => null, 'region_diff' => null];
        if ($fileName1 === null || $fileName2 === null) {
            return $unjudged;
        }

        try {
            $img1 = $this->loadImage($this->thumbnailPath($fileName1));
            $img2 = $this->loadImage($this->thumbnailPath($fileName2));
            if ($img1 === null || $img2 === null) {
                return $unjudged;
            }

            return [
                'ssim' => $this->ssimBetween($img1, $img2),
                'region_diff' => RegionDifference::between($img1, $img2),
            ];
        } catch (\Throwable) {
            return $unjudged;
        }
    }

    private function thumbnailPath(string $fileName): string
    {
        return $this->thumbDirectory . pathinfo($fileName, PATHINFO_FILENAME) . '@2x.webp';
    }

    /**
     * SSIM between two images: the luminance channels compared at a reduced
     * resolution for performance. Between 0.0 (totally different) and 1.0
     * (identical).
     */
    private function ssimBetween(\GdImage $img1, \GdImage $img2): float
    {
        // Resize both to a common small size for fast SSIM comparison
        $size = 64;
        $r1 = imagecreatetruecolor($size, $size);
        $r2 = imagecreatetruecolor($size, $size);

        imagecopyresampled($r1, $img1, 0, 0, 0, 0, $size, $size, imagesx($img1), imagesy($img1));
        imagecopyresampled($r2, $img2, 0, 0, 0, 0, $size, $size, imagesx($img2), imagesy($img2));

        return $this->ssimFromLuminance($this->extractLuminance($r1, $size), $this->extractLuminance($r2, $size));
    }

    /**
     * Load an image file into a GD resource.
     */
    private function loadImage(string $path): ?\GdImage
    {
        $info = @getimagesize($path);
        if ($info === false) {
            return null;
        }

        $type = $info[2];

        // AVIF support: constant exists in PHP 8.1+ but GD may lack the function
        if (defined('IMAGETYPE_AVIF') && $type === IMAGETYPE_AVIF) {
            $avif = function_exists('imagecreatefromavif') ? @imagecreatefromavif($path) : null;
            return $avif instanceof \GdImage ? $avif : null;
        }

        // The imagecreatefrom* functions return GdImage|false; normalize false to null.
        $img = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG  => @imagecreatefrompng($path),
            IMAGETYPE_GIF  => @imagecreatefromgif($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            IMAGETYPE_BMP  => @imagecreatefrombmp($path),
            default        => null,
        };

        return $img instanceof \GdImage ? $img : null;
    }

    /**
     * Extract luminance values from a square GD image.
     *
     * @return float[] Flat array of luminance values [0..255]
     */
    private function extractLuminance(\GdImage $img, int $size): array
    {
        $lum = [];
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $rgb = imagecolorat($img, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                // ITU-R BT.601 luminance
                $lum[] = 0.299 * $r + 0.587 * $g + 0.114 * $b;
            }
        }
        return $lum;
    }

    /**
     * Compute SSIM from two luminance arrays.
     * Uses 8x8 sliding windows with the standard SSIM formula.
     *
     * Constants: C1 = (0.01*255)^2, C2 = (0.03*255)^2
     *
     * @param float[] $lum1
     * @param float[] $lum2
     */
    private function ssimFromLuminance(array $lum1, array $lum2): float
    {
        $size = (int) sqrt(count($lum1));
        $windowSize = 8;
        $c1 = 6.5025;   // (0.01 * 255)^2
        $c2 = 58.5225;  // (0.03 * 255)^2

        $ssimSum = 0.0;
        $windowCount = 0;

        for ($y = 0; $y <= $size - $windowSize; $y += $windowSize) {
            for ($x = 0; $x <= $size - $windowSize; $x += $windowSize) {
                $w1 = [];
                $w2 = [];

                for ($wy = 0; $wy < $windowSize; $wy++) {
                    for ($wx = 0; $wx < $windowSize; $wx++) {
                        $idx = ($y + $wy) * $size + ($x + $wx);
                        $w1[] = $lum1[$idx];
                        $w2[] = $lum2[$idx];
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
     * Load all dismissed duplicate pairs from the database.
     *
     * @return array<string, true> Keys are "smallerId:largerId"
     */
    private function loadDismissedPairs(): array
    {
        $dismissed = [];

        try {
            $db = $this->db;
            $stmt = $db->query('SELECT media_id_1, media_id_2 FROM dismissed_duplicates');

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $key = $this->pairKey((int) $row['media_id_1'], (int) $row['media_id_2']);
                $dismissed[$key] = true;
            }
        } catch (\Throwable) {
            // Table may not exist yet — treat as no dismissed pairs
        }

        return $dismissed;
    }

    /**
     * Create a canonical key for a pair of media IDs.
     */
    private function pairKey(int $id1, int $id2): string
    {
        return min($id1, $id2) . ':' . max($id1, $id2);
    }

    /**
     * @param array<string, mixed> $result
     * @return string The path the report was written to.
     * @throws \RuntimeException If the report directory cannot be created.
     */
    private function saveReport(array $result): string
    {
        if (!is_dir($this->reportDirectory)) {
            if (!mkdir($this->reportDirectory, 0o755, true) && !is_dir($this->reportDirectory)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $this->reportDirectory));
            }
        }

        // The UI serves the newest dupes-*.json, so a tuning run must not match it.
        $path = $this->reportDirectory . ($this->tuning
            ? 'tuning-' . date('Y-m-d-His') . '.json'
            : 'dupes-' . date('Y-m-d') . '.json');

        file_put_contents($path, json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return $path;
    }
}
