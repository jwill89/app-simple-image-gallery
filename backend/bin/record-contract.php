<?php

declare(strict_types=1);

/**
 * Record the Slim API's contract (Phase 0 of docs/laravel-migration.md).
 *
 * Runs every case in tests/Fixtures/contract-cases.php against a staged copy
 * of the app on the contract fixture, and writes one recording per case to
 * tests/Fixtures/contract/, plus index.json. Nothing in backend/db, media,
 * cache or logs is touched; see tests/Support/Contract/StagedApp.php.
 *
 *   php bin/record-contract.php                 re-record everything
 *   php bin/record-contract.php --only=media/   re-record cases whose id starts with media/
 *   php bin/record-contract.php --check         record to a temporary directory and exit 1
 *                                               if anything differs from the committed files
 */

use Gallery\Tests\Support\Contract\Recorder;
use Gallery\Tests\Support\Contract\StagedApp;

require __DIR__ . '/../vendor/autoload.php';

$backend = dirname(__DIR__);
$committed = $backend . '/tests/Fixtures/contract';
$options = getopt('', ['only:', 'check']);
$only = is_string($options['only'] ?? null) ? $options['only'] : null;
$check = isset($options['check']);

/** @var list<array<string, mixed>> $cases */
$cases = require $backend . '/tests/Fixtures/contract-cases.php';
Recorder::checkCoverage($cases);

if ($only !== null) {
    $cases = array_values(array_filter($cases, static fn(array $case): bool => str_starts_with((string) $case['id'], $only)));
    if ($cases === []) {
        fwrite(STDERR, "No case id starts with {$only}.\n");
        exit(1);
    }
}

$outDir = $check ? sys_get_temp_dir() . '/gallery-contract-check-' . bin2hex(random_bytes(4)) : $committed;

/** @return list<string> Recording paths under $dir, relative to it. */
$recordings = static function (string $dir): array {
    if (!is_dir($dir)) {
        return [];
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    $found = [];
    foreach ($files as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'json') {
            $found[] = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
        }
    }
    sort($found);

    return $found;
};

// A full run replaces every recording, so a removed case leaves no stale file.
if ($only === null && !$check) {
    foreach ($recordings($committed) as $stale) {
        unlink("{$committed}/{$stale}");
    }
}

$app = new StagedApp($backend);
$recorder = new Recorder($app);
$index = [];

try {
    $app->stage();
    foreach ($cases as $case) {
        $recording = $recorder->record($case);
        $path = "{$outDir}/{$recording['id']}.json";
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }
        file_put_contents($path, Recorder::encode($recording) . "\n");

        /** @var list<array{response: array{status: int}}> $steps */
        $steps = $recording['steps'];
        $statuses = array_map(static fn(array $step): int => $step['response']['status'], $steps);
        $index[] = ['id' => $recording['id'], 'route' => $recording['route'], 'statuses' => $statuses];
        printf("%-50s %s\n", $recording['id'], implode(' ', $statuses));
    }
} finally {
    $app->remove();
}

if ($only === null) {
    $routes = array_fill_keys([...Recorder::ROUTES, ...Recorder::CROSS_CUTTING], []);
    foreach ($index as $entry) {
        $routes[$entry['route']][] = $entry['id'];
    }
    file_put_contents("{$outDir}/index.json", Recorder::encode([
        'about' => 'Recorded by bin/record-contract.php; see README.md. Each route lists the cases that exercise it.',
        'routes' => $routes,
        'cases' => $index,
    ]) . "\n");
}

if (!$check) {
    printf("\n%d cases recorded to %s\n", count($index), $outDir);
    exit(0);
}

$differences = [];
$fresh = $recordings($outDir);
$existing = $only === null
    ? $recordings($committed)
    : array_values(array_filter($recordings($committed), static fn(string $file): bool => in_array($file, $fresh, true)));
foreach (array_unique([...$fresh, ...$existing]) as $file) {
    $now = @file_get_contents("{$outDir}/{$file}");
    $before = @file_get_contents("{$committed}/{$file}");
    if ($now !== $before) {
        $differences[] = match (false) {
            $now => "removed: {$file}",
            $before => "new:     {$file}",
            default => "changed: {$file}",
        };
    }
}

echo $differences === []
    ? "\nThe recordings match the committed contract.\n"
    : "\nThe recordings differ from the committed contract (fresh copy in {$outDir}):\n  " . implode("\n  ", $differences) . "\n";
exit($differences === [] ? 0 : 1);
