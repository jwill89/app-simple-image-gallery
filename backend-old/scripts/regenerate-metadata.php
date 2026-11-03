<?php

// CLI-only guard
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'This script can only be run from the command line.';
    exit(1);
}

// Set working directory to project root so all relative paths resolve correctly
chdir(__DIR__ . '/..');

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

require __DIR__ . '/../vendor/autoload.php';

use Gallery\Collection\MediaCollection;
use Gallery\Core\DatabaseConnection;

set_time_limit(0);

$start_time = microtime(true);

$container = new \DI\ContainerBuilder()->addDefinitions(__DIR__ . '/../api/dependencies.php')->build();
$media_collection = $container->get(MediaCollection::class);
$full_dir = MediaCollection::MEDIA_DIRECTORY_FULL;

echo "Regenerating media metadata (dimensions, duration, file size)...\n\n";

$all_media = $media_collection->getAll();
$total = count($all_media);
$success = 0;
$failed = 0;

$db = DatabaseConnection::getInstance();
$db->beginTransaction();

try {
    foreach ($all_media as $i => $media) {
        $num = $i + 1;
        $source = $full_dir . $media->file_name;

        if (!file_exists($source)) {
            echo "  [{$num}/{$total}] SKIP (missing): {$media->file_name}\n";
            $failed++;
            continue;
        }

        if ($media_collection->refreshMetadata($media)) {
            echo "  [{$num}/{$total}] OK: {$media->file_name} "
                . "({$media->width}x{$media->height}"
                . ($media->duration > 0 ? ", {$media->duration}s" : '')
                . ")\n";
            $success++;
        } else {
            echo "  [{$num}/{$total}] FAIL: {$media->file_name}\n";
            $failed++;
        }
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    echo "[ERROR] Metadata regeneration failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nResults: {$success} updated, {$failed} skipped/failed out of {$total}\n";

$execution_time = round(microtime(true) - $start_time, 2);
echo "Completed in {$execution_time}s\n";
