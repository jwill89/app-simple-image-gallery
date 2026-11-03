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

set_time_limit(0);

$start_time = microtime(true);

$container = new \DI\ContainerBuilder()->addDefinitions(__DIR__ . '/../api/dependencies.php')->build();
$media_collection = $container->get(MediaCollection::class);

$thumb_dir = MediaCollection::MEDIA_DIRECTORY_THUMBNAILS;
$full_dir = MediaCollection::MEDIA_DIRECTORY_FULL;

// ============================================================
// Delete ALL old thumbnails (non-webp) from thumbs directory
// ============================================================

echo "Cleaning old thumbnails...\n";

$old_removed = 0;
if (is_dir($thumb_dir)) {
    foreach (scandir($thumb_dir) as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($ext !== 'webp') {
            unlink($thumb_dir . $file);
            $old_removed++;
        }
    }
}

echo "Removed {$old_removed} old thumbnail files.\n\n";

// ============================================================
// Regenerate Thumbnails (1x + 2x WebP)
// ============================================================

echo "Regenerating thumbnails...\n";

$all_media = $media_collection->getAll();
$total = count($all_media);
$success = 0;
$failed = 0;

foreach ($all_media as $i => $media) {
    $source = $full_dir . $media->file_name;
    $num = $i + 1;

    if (!file_exists($source)) {
        echo "  [{$num}/{$total}] SKIP (missing): {$media->file_name}\n";
        $failed++;
        continue;
    }

    try {
        $media_collection->createThumbnail($media, $full_dir);
        echo "  [{$num}/{$total}] OK: {$media->file_name}\n";
        $success++;
    } catch (Exception $e) {
        echo "  [{$num}/{$total}] FAIL: {$media->file_name} - {$e->getMessage()}\n";
        $failed++;
    }
}

echo "\nResults: {$success} regenerated, {$failed} failed out of {$total}\n";

$end_time = microtime(true);
$execution_time = round($end_time - $start_time, 2);
echo "Completed in {$execution_time}s\n";
