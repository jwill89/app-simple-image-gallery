<?php

// CLI-only guard
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'This script can only be run from the command line.';
    exit(1);
}

// Set working directory to project root so all relative paths resolve correctly
chdir(__DIR__ . '/..');

// Error logging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Autoloader
require __DIR__ . '/../vendor/autoload.php';

use Gallery\Core\DatabaseConnection;
use Gallery\Core\DanbooruTagger;

// Set Time Limit - 30 minutes for large galleries
set_time_limit(1800);

// Parse command line arguments
$verbose = false;
$singleMediaId = null;

/** @var list<string> $cliArgs CLI arguments (requires register_argc_argv, on by default for CLI). */
$cliArgs = $_SERVER['argv'] ?? [];

foreach (array_slice($cliArgs, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        echo "Usage: php scripts/tag_imports.php [options] [media_id]\n\n";
        echo "  media_id  (optional) Process only the specified media ID\n\n";
        echo "Options:\n";
        echo "  -v, --verbose   Show detailed debug output for each lookup\n";
        echo "  -h, --help      Show this help message\n\n";
        echo "Examples:\n";
        echo "  php scripts/tag_imports.php              # Process all media\n";
        echo "  php scripts/tag_imports.php 42           # Process only media #42\n";
        echo "  php scripts/tag_imports.php -v 42        # Process media #42 with debug output\n";
        exit(0);
    } elseif ($arg === '-v' || $arg === '--verbose') {
        $verbose = true;
    } elseif (is_numeric($arg)) {
        $singleMediaId = (int) $arg;
    }
}

// Verify Danbooru credentials are configured
if (!DanbooruTagger::isConfigured()) {
    echo "Error: Danbooru credentials are not configured.\n";
    echo "Set DANBOORU_LOGIN and DANBOORU_API_KEY in your .env file.\n";
    exit(1);
}

// Get database connection
$db = DatabaseConnection::getInstance();

// Get all media to process (MD5 works for any type; IQDB fallback covers re-encoded files)
if ($singleMediaId !== null) {
    $stmt = $db->prepare('SELECT media_id, hash, file_name FROM media WHERE media_id = :id');
    $stmt->execute([':id' => $singleMediaId]);
    $mediaItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($mediaItems === []) {
        echo "Error: Media #{$singleMediaId} not found in database.\n";
        exit(1);
    }
} else {
    $stmt = $db->query('SELECT media_id, hash, file_name FROM media');
    $mediaItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$totalItems = count($mediaItems);
echo "Gallery - Danbooru Tag Import\n";
echo "==============================\n\n";
echo "Found {$totalItems} media items to process.";
if ($verbose) {
    echo " (verbose mode)";
}
echo "\n\n";

// Create tagger instance (loads import rules from DB, warms tag/category caches)
$container = new \DI\ContainerBuilder()->addDefinitions(__DIR__ . '/../api/dependencies.php')->build();
$tagger = $container->get(DanbooruTagger::class);

// Enable debug output if verbose
if ($verbose) {
    $tagger->setDebugCallback(function (string $msg) {
        echo $msg . "\n";
    });
}

// Track aggregate stats
$stats = [
    'processed'         => 0,
    'found_on_danbooru' => 0,
    'found_by_md5'      => 0,
    'found_by_iqdb'     => 0,
    'tags_applied'      => 0,
    'tags_created'      => 0,
    'errors'            => 0,
];

// Process each media item
foreach ($mediaItems as $index => $item) {
    $mediaId = (int) $item['media_id'];
    $md5 = $item['hash'];
    $fileName = $item['file_name'];
    $current = $index + 1;

    echo "[{$current}/{$totalItems}] Processing media #{$mediaId} ({$fileName})... ";
    if ($verbose) {
        echo "\n";
    }

    try {
        $result = $tagger->importTagsForMedia($mediaId, $md5, $fileName);

        $stats['processed']++;

        if ($result['found']) {
            $stats['found_on_danbooru']++;
            $stats['tags_applied'] += $result['tags_applied'];
            $stats['tags_created'] += $result['tags_created'];

            if ($result['method'] === 'md5') {
                $stats['found_by_md5']++;
            } elseif ($result['method'] === 'iqdb') {
                $stats['found_by_iqdb']++;
            }

            $msg = "applied {$result['tags_applied']} tags (via {$result['method']}).";
        } else {
            $msg = "not found on Danbooru.";
        }

        if ($verbose) {
            echo "  Result: {$msg}\n";
        } else {
            echo "{$msg}\n";
        }
    } catch (\Throwable $e) {
        $stats['errors']++;
        echo "ERROR: {$e->getMessage()}\n";
    }

    // Rate limit: be polite to Danbooru API
    // IQDB uploads are heavier — wait longer when we had to fall back
    $delay = (isset($result) && $result['method'] === 'iqdb') ? 2000000 : 1000000;
    usleep($delay);
}

// Summary
echo "\n\n==============================\n";
echo "Import Complete!\n";
echo "==============================\n";
echo "Media processed:       {$stats['processed']}\n";
echo "Found on Danbooru:     {$stats['found_on_danbooru']}\n";
echo "  Matched by MD5:      {$stats['found_by_md5']}\n";
echo "  Matched by IQDB:     {$stats['found_by_iqdb']}\n";
echo "Tags applied:          {$stats['tags_applied']}\n";
echo "Tags created:          {$stats['tags_created']}\n";
echo "Errors:                {$stats['errors']}\n";
