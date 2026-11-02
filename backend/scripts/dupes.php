<?php

// CLI-only guard
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'This script can only be run from the command line.';
    exit(1);
}

// Set working directory to project root so all relative paths resolve correctly
chdir(__DIR__ . '/..');

// Error logging (no display in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('memory_limit', '1024M');

// Autoloader
require __DIR__ . '/../vendor/autoload.php';

use Gallery\Core\Configuration;
use Gallery\Core\DatabaseConnection;
use Gallery\Core\DuplicateScanner;

$maxAllowed = Configuration::MAX_DUPLICATE_MAX_DISTANCE;
$defaultDistance = Configuration::DEFAULT_DUPLICATE_MAX_DISTANCE;
$defaultRegionDiff = Configuration::DEFAULT_DUPLICATE_MAX_REGION_DIFF;
$usage = <<<TXT
    Usage: php scripts/dupes.php [--max-distance=N] [--max-region-diff=X|off] [--tune]

      --max-distance=N         Largest Hamming distance reported, 0 to {$maxAllowed}
                               (default: DUPLICATE_MAX_DISTANCE from .env, else {$defaultDistance})
      --max-region-diff=X|off  Largest difference, 0 to 255, allowed in the region where a pair
                               differs most; "off" reports every pair within the distance
                               (default: DUPLICATE_MAX_REGION_DIFF from .env, else {$defaultRegionDiff})
      --tune                   Calibration run: keeps the pairs you dismissed (flagged), prints a
                               distance x region-difference table, and writes
                               dupes/tuning-<timestamp>.json instead of the report the Duplicates
                               page reads

    TXT;

/**
 * The last value given for a long option, or null if it wasn't given a value.
 *
 * @param array<string, string|false|list<string|false>> $options
 */
function optionValue(array $options, string $name): ?string
{
    $value = $options[$name] ?? null;
    if (is_array($value)) {
        $value = end($value);
    }

    return is_string($value) ? $value : null;
}

function usageError(string $message, string $usage): never
{
    fwrite(STDERR, $message . "\n\n" . $usage);
    exit(1);
}

$options = getopt('', ['max-distance:', 'max-region-diff:', 'tune', 'help']);
if ($options === false) {
    usageError('Could not read the options.', $usage);
}
if (isset($options['help'])) {
    echo $usage;
    exit(0);
}

$maxDistance = Configuration::getDuplicateMaxDistance();
$rawDistance = optionValue($options, 'max-distance');
if ($rawDistance !== null) {
    $maxDistance = Configuration::parseDuplicateMaxDistance($rawDistance)
        ?? usageError("--max-distance must be a whole number from 0 to {$maxAllowed}.", $usage);
}

$maxRegionDiff = Configuration::getDuplicateMaxRegionDiff();
$rawRegionDiff = optionValue($options, 'max-region-diff');
if ($rawRegionDiff !== null) {
    $maxRegionDiff = strtolower(trim($rawRegionDiff)) === 'off'
        ? null
        : (Configuration::parseDuplicateMaxRegionDiff($rawRegionDiff)
            ?? usageError('--max-region-diff must be a number from 0 to 255, or "off".', $usage));
}

$tuning = isset($options['tune']);

// Set Time Limit for Script (unlimited)
set_time_limit(0);

// Instruct PHP to continue execution
ignore_user_abort(true);

// Run the scanner (on the connection api/dependencies.php hands everything else)
$scanner = new DuplicateScanner(
    DatabaseConnection::getInstance(),
    maxDistance: $maxDistance,
    maxRegionDiff: $maxRegionDiff,
    tuning: $tuning,
);
$result = $scanner->run();

// Output summary (CLI plain text)
$settings = $result['settings'];
echo "Settings: max distance {$settings['max_distance']} (LSH bands of {$settings['band_bits']} bits), "
    . 'max region diff ' . ($settings['max_region_diff'] ?? 'off') . ($tuning ? ', tuning run' : '') . "\n";
echo "Images Compared: {$result['images_compared']}\n";
echo "LSH Candidates: " . number_format($result['lsh_candidates']) . "\n";
if ($result['skipped_buckets'] > 0) {
    echo "Skipped Buckets: {$result['skipped_buckets']} (over 100 images shared a band; pairs that only "
        . "meet in such a band are missed, so try a lower --max-distance)\n";
}
if ($settings['max_region_diff'] !== null) {
    echo "Rejected Above Max Region Diff: {$result['region_rejected']}\n";
}
echo "Duplicates Found: {$result['duplicates_found']}\n";
echo "Execution Time: {$result['execution_time_seconds']}s\n";
echo "Report: {$result['report_file']}\n";

if (!$tuning) {
    exit(0);
}

// Tuning table: pairs by Hamming distance (rows) and region difference
// (columns), with the pairs you dismissed counted separately. Run it with
// --max-region-diff=off to see every pair. Re-saved copies score low and
// variants high; set the ceiling between the real duplicates and the dismissed
// pairs. "n/a" is a pair whose thumbnails couldn't be read.
$columns = ['<15', '15-25', '25-35', '35-50', '50-80', '>=80', 'n/a'];
$columnFor = static fn(?float $diff): int => match (true) {
    $diff === null => 6,
    $diff < 15 => 0,
    $diff < 25 => 1,
    $diff < 35 => 2,
    $diff < 50 => 3,
    $diff < 80 => 4,
    default => 5,
};

/** @var array<string, array<int, array<int, int>>> $tables title => distance => column => pairs */
$tables = ['Pairs' => [], 'Dismissed pairs' => []];
foreach ($result['matches'] as $match) {
    $table = ($match[5] ?? false) ? 'Dismissed pairs' : 'Pairs';
    $column = $columnFor($match[4]);
    $tables[$table][$match[2]][$column] = ($tables[$table][$match[2]][$column] ?? 0) + 1;
}

foreach ($tables as $title => $rows) {
    echo "\n{$title} by distance and region difference:\n";
    echo str_pad('distance', 10) . implode('', array_map(static fn(string $c): string => str_pad($c, 11), $columns)) . "\n";
    for ($distance = 0; $distance <= $maxDistance; $distance++) {
        echo str_pad((string) $distance, 10);
        foreach (array_keys($columns) as $column) {
            echo str_pad((string) ($rows[$distance][$column] ?? 0), 11);
        }
        echo "\n";
    }
}
