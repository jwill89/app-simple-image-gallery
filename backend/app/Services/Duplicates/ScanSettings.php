<?php

declare(strict_types=1);

namespace App\Services\Duplicates;

/**
 * What the duplicate scan reports: how close two fingerprints must be, how far
 * a pair's thumbnails may differ in their worst region, and whether this is a
 * tuning run.
 */
final readonly class ScanSettings
{
    /**
     * Hamming cutoff (bits out of 64) when DUPLICATE_MAX_DISTANCE is unset.
     * Re-saved and resized copies of gallery images landed 0-5 bits from their
     * originals (2026-11 calibration); the region check, not this, is what keeps
     * variants out.
     */
    public const int DEFAULT_MAX_DISTANCE = 6;

    /**
     * Region-difference ceiling (0-255 levels, see RegionDifference) when
     * DUPLICATE_MAX_REGION_DIFF is unset. On the 2026-11 calibration, re-saved
     * copies scored at most 32.7 and the 26 variants dismissed in the UI at
     * least 40.9; this sits between.
     */
    public const float DEFAULT_MAX_REGION_DIFF = 36.0;

    /**
     * Highest max distance accepted: a quarter of the 64-bit hash. Past that the
     * LSH bands the scanner needs to keep its no-missed-pairs guarantee shrink
     * to 3 bits or fewer, and nearly every bucket overflows.
     */
    public const int HIGHEST_MAX_DISTANCE = 16;

    public function __construct(
        /** Largest Hamming distance (bits out of 64) still reported. */
        public int $maxDistance = self::DEFAULT_MAX_DISTANCE,
        /** Largest region difference (0-255) still reported; null turns the check off. */
        public ?float $maxRegionDiff = self::DEFAULT_MAX_REGION_DIFF,
        /**
         * A tuning run keeps pairs the user has dismissed (flagged as such, as
         * known non-duplicates to calibrate against) and writes its report to
         * tuning-<timestamp>.json, leaving the dupes-*.json the UI reads alone.
         */
        public bool $tuning = false,
    ) {}

    /**
     * The settings in config/gallery.php. An unset or invalid value falls back
     * to its default, and a region difference of "off" turns the check off.
     */
    public static function fromConfig(): self
    {
        $regionDiff = config()->string('gallery.duplicates.max_region_diff');

        return new self(
            self::parseMaxDistance(config()->string('gallery.duplicates.max_distance')) ?? self::DEFAULT_MAX_DISTANCE,
            strtolower(trim($regionDiff)) === 'off'
                ? null
                : self::parseMaxRegionDiff($regionDiff) ?? self::DEFAULT_MAX_REGION_DIFF,
        );
    }

    /**
     * A max distance from text: a whole number from 0 to HIGHEST_MAX_DISTANCE,
     * or null if it isn't one.
     */
    public static function parseMaxDistance(string $value): ?int
    {
        $value = trim($value);

        if (preg_match('/^\d{1,2}$/', $value) !== 1) {
            return null;
        }

        $distance = (int) $value;

        return $distance <= self::HIGHEST_MAX_DISTANCE ? $distance : null;
    }

    /**
     * A region-difference ceiling from text: a number from 0 to 255, or null if
     * it isn't one.
     */
    public static function parseMaxRegionDiff(string $value): ?float
    {
        $value = trim($value);

        if (!is_numeric($value)) {
            return null;
        }

        $diff = (float) $value;

        return $diff >= 0.0 && $diff <= 255.0 ? $diff : null;
    }
}
