<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Duplicates\DuplicateScanner;
use App\Services\Duplicates\ScanSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs the duplicate scan, as POST /duplicates/scan does, with the cutoffs
 * from config/gallery.php unless an option sets them. With --tune it is the
 * calibration run that sets them: see ScanSettings::$tuning, and the table it
 * prints.
 */
#[Description('Scan the images for duplicates and write the report')]
#[Signature('gallery:dupes
        {--max-distance= : Largest Hamming distance reported, 0 to 16 (default: DUPLICATE_MAX_DISTANCE, else 6)}
        {--max-region-diff= : Largest difference, 0 to 255, in the region where a pair differs most; "off" reports every pair within the distance (default: DUPLICATE_MAX_REGION_DIFF, else 36)}
        {--tune : Calibration run: keeps the pairs you dismissed (flagged), prints a distance by region-difference table, and writes tuning-<timestamp>.json instead of the report the Duplicates page reads}')]
final class FindDuplicates extends Command
{
    /** The region-difference columns of the tuning table, and the upper bound of each. */
    private const array COLUMNS = ['<15' => 15, '15-25' => 25, '25-35' => 35, '35-50' => 50, '50-80' => 80, '>=80' => INF];

    public function handle(DuplicateScanner $scanner): int
    {
        $defaults = ScanSettings::fromConfig();

        $maxDistance = $defaults->maxDistance;
        $distanceOption = $this->option('max-distance');
        if (is_string($distanceOption)) {
            $maxDistance = ScanSettings::parseMaxDistance($distanceOption);
            if ($maxDistance === null) {
                $this->error('--max-distance must be a whole number from 0 to ' . ScanSettings::HIGHEST_MAX_DISTANCE . '.');

                return self::INVALID;
            }
        }

        $maxRegionDiff = $defaults->maxRegionDiff;
        $regionOption = $this->option('max-region-diff');
        if (is_string($regionOption)) {
            $off = strtolower(trim($regionOption)) === 'off';
            $maxRegionDiff = $off ? null : ScanSettings::parseMaxRegionDiff($regionOption);
            if (!$off && $maxRegionDiff === null) {
                $this->error('--max-region-diff must be a number from 0 to 255, or "off".');

                return self::INVALID;
            }
        }

        $tuning = (bool) $this->option('tune');
        $result = $scanner->run(new ScanSettings($maxDistance, $maxRegionDiff, $tuning));

        $settings = $result['settings'];
        $this->line("Settings: max distance {$settings['max_distance']} (LSH bands of {$settings['band_bits']} bits), "
            . 'max region diff ' . ($settings['max_region_diff'] ?? 'off') . ($tuning ? ', tuning run' : ''));
        $this->line("Images Compared: {$result['images_compared']}");
        $this->line('LSH Candidates: ' . number_format($result['lsh_candidates']));
        if ($result['skipped_buckets'] > 0) {
            $this->warn("Skipped Buckets: {$result['skipped_buckets']} (over 100 images shared a band; pairs that only "
                . 'meet in such a band are missed, so try a lower --max-distance)');
        }
        if ($settings['max_region_diff'] !== null) {
            $this->line("Rejected Above Max Region Diff: {$result['region_rejected']}");
        }
        $this->line("Duplicates Found: {$result['duplicates_found']}");
        $this->line("Execution Time: {$result['execution_time_seconds']}s");
        $this->line("Report: {$result['report_file']}");

        if ($tuning) {
            $this->tuningTables($result['matches'], $maxDistance);
        }

        return self::SUCCESS;
    }

    /**
     * Pairs by Hamming distance (rows) and region difference (columns), with
     * the pairs you dismissed counted apart. Re-saved copies score low and
     * variants high: set the ceiling between the real duplicates and the
     * dismissed pairs, after a run with --max-region-diff=off to see them all.
     * "n/a" is a pair whose thumbnails couldn't be read.
     *
     * @param list<array{0: int, 1: int, 2: int, 3: ?float, 4: ?float, 5?: bool}> $matches
     */
    private function tuningTables(array $matches, int $maxDistance): void
    {
        $columns = [...array_keys(self::COLUMNS), 'n/a'];
        $columnFor = static function (?float $difference) use ($columns): int {
            if ($difference === null) {
                return count($columns) - 1;
            }
            $column = 0;
            foreach (self::COLUMNS as $below) {
                if ($difference < $below) {
                    return $column;
                }
                $column++;
            }

            return $column;
        };

        /** @var array<string, array<int, array<int, int>>> $tables title => distance => column => pairs */
        $tables = ['Pairs' => [], 'Dismissed pairs' => []];
        foreach ($matches as $match) {
            $table = ($match[5] ?? false) ? 'Dismissed pairs' : 'Pairs';
            $column = $columnFor($match[4]);
            $tables[$table][$match[2]][$column] = ($tables[$table][$match[2]][$column] ?? 0) + 1;
        }

        foreach ($tables as $title => $rows) {
            $this->newLine();
            $this->line("{$title} by distance and region difference:");
            $this->line(str_pad('distance', 10) . implode('', array_map(static fn(string $column): string => str_pad($column, 11), $columns)));
            for ($distance = 0; $distance <= $maxDistance; $distance++) {
                $line = str_pad((string) $distance, 10);
                foreach (array_keys($columns) as $column) {
                    $line .= str_pad((string) ($rows[$distance][$column] ?? 0), 11);
                }
                $this->line($line);
            }
        }
    }
}
