<?php

/**
 * Regenerate perceptual hash fingerprints for all image media.
 *
 * Run this after anything changes how fingerprints are computed (the hash
 * algorithm, the library, the image driver) to recalculate every stored one:
 * fingerprints are only comparable with others computed the same way.
 *
 * An image that can't be hashed (missing file, decode failure) has its
 * fingerprint cleared rather than left as it was, so the duplicate scan skips
 * it instead of comparing an old-style fingerprint against new ones.
 *
 * Usage: php scripts/regenerate-fingerprints.php
 */

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
$db = DatabaseConnection::getInstance();

// Get all image media
$all_media = $media_collection->getAll();
$images = array_filter($all_media, fn($m) => $m->isImage());
$total = count($images);

echo "Regenerating perceptual hash fingerprints for {$total} image(s)...\n\n";

$success = 0;
$failed = 0;

$updateStmt = $db->prepare('UPDATE media SET bits_fingerprint = :fp WHERE media_id = :id');

foreach (array_values($images) as $i => $media) {
    $num = $i + 1;
    $fileName = $media->file_name;
    $filePath = MediaCollection::MEDIA_DIRECTORY_FULL . $fileName;

    if (!file_exists($filePath)) {
        $updateStmt->execute([':fp' => '', ':id' => $media->media_id]);
        echo "  [{$num}/{$total}] SKIP (missing, fingerprint cleared): {$fileName}\n";
        $failed++;
        continue;
    }

    try {
        $media_collection->createFingerprint($media, MediaCollection::MEDIA_DIRECTORY_FULL);
        $fp = $media->bits_fingerprint;

        $updateStmt->execute([
            ':fp' => $fp,
            ':id' => $media->media_id,
        ]);

        echo "  [{$num}/{$total}] OK: {$fileName}\n";
        $success++;
    } catch (\Throwable $e) {
        $updateStmt->execute([':fp' => '', ':id' => $media->media_id]);
        echo "  [{$num}/{$total}] FAIL (fingerprint cleared): {$fileName} - {$e->getMessage()}\n";
        $failed++;
    }
}

$end_time = microtime(true);
$execution_time = round($end_time - $start_time, 2);

echo "\nResults: {$success} updated, {$failed} failed out of {$total}\n";
echo "Completed in {$execution_time}s\n";
