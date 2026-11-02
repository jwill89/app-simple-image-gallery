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
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Autoloader
require __DIR__ . '/../vendor/autoload.php';

// DB and Media functions
use Gallery\Collection\MediaCollection;
use Gallery\Core\DatabaseConnection;
use Gallery\Structure\Media;

// Set Time Limit for Script, 10 minutes
set_time_limit(600);

// Set Start Time
$start_time = microtime(true);

// Setup Collection (built from the shared DI container)
$container = new \DI\ContainerBuilder()->addDefinitions(__DIR__ . '/../api/dependencies.php')->build();
$media_collection = $container->get(MediaCollection::class);

// Directory Constants
$media_dir = MediaCollection::MEDIA_DIRECTORY;
$media_dir_full = MediaCollection::MEDIA_DIRECTORY_FULL;

// ============================================================
// Scan Input Folder (new files waiting to be processed)
// ============================================================

// Get new files in the input folder (excludes directories)
$files_in_folder = array_values(array_filter(
    scandir($media_dir),
    static fn($item) => !is_dir($media_dir . $item)
));

// ============================================================
// Load Database Records & Build Lookup Maps
// ============================================================

// Get lightweight summaries (only id, file_name, hash, media_type)
$all_summaries = $media_collection->getAllSummary();

// Build hash map and filename set for O(1) lookups
$known_hashes = [];      // MD5 hash => true
$known_filenames = [];   // filename => true (files that exist in full/)

foreach ($all_summaries as $item) {
    $known_hashes[$item['hash']] = true;
    $known_filenames[$item['file_name']] = true;
}

// Supported file extensions
$supported_extensions = [
    'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'tif', 'avif',
    'mp4', 'webm', 'mov', 'avi', 'mkv', 'flv', 'wmv', 'm4v',
];

// ============================================================
// Initialize Counters
// ============================================================
$media_added = 0;
$media_removed = 0;
$media_skipped = 0;

// ============================================================
// Remove Orphaned Database Entries
// (DB records whose files no longer exist on disk)
// ============================================================

$db = DatabaseConnection::getInstance();
$db->beginTransaction();

try {
    foreach ($all_summaries as $item) {
        if (!file_exists($media_dir_full . $item['file_name'])) {
            $media_obj = $media_collection->get($item['media_id']);
            if ($media_obj !== null && $media_collection->delete($media_obj)) {
                $media_removed++;
                unset($known_hashes[$item['hash']], $known_filenames[$item['file_name']]);
            }
        }
    }

    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    echo "[ERROR] Failed to remove orphaned entries: " . $e->getMessage() . "\n";
}

// Free summary array (no longer needed)
unset($all_summaries);

// ============================================================
// Process New Media Files
// ============================================================

$db->beginTransaction();

try {
    foreach ($files_in_folder as $file_name) {
        $file_path = $media_dir . $file_name;
        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        // Skip unsupported extensions
        if (!in_array($ext, $supported_extensions, true)) {
            $media_skipped++;
            continue;
        }

        // Detect media type (animated GIFs → 'video', static GIFs → 'image')
        $media_type = MediaCollection::detectMediaType($file_path);

        // Skip if this filename is already in the database
        if (isset($known_filenames[$file_name])) {
            unlink($file_path);
            $media_skipped++;
            continue;
        }

        // Compute MD5 hash to check for content duplicates
        $file_md5 = md5_file($file_path);
        if ($file_md5 === false) {
            $media_skipped++;
            continue;
        }

        if (isset($known_hashes[$file_md5])) {
            // Duplicate content — delete the new file
            unlink($file_path);
            $media_skipped++;
            continue;
        }

        // Create and save the new media item
        $media = new Media();
        $media->setMediaType($media_type)
            ->setFileName($file_name)
            ->setFileTime((int) filemtime($file_path))
            ->setHash($file_md5);

        // Save (auto-creates thumbnail and fingerprint for images)
        if ($media_collection->save($media) !== 0) {
            // Move file to the full-size directory
            rename($file_path, $media_dir_full . $file_name);

            // Add to lookup maps so subsequent files in this batch are checked
            $known_hashes[$file_md5] = true;
            $known_filenames[$file_name] = true;

            $media_added++;
        } else {
            $media_skipped++;
        }
    }

    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    echo "[ERROR] Failed to process new media: " . $e->getMessage() . "\n";
}

// ============================================================
// Output Results
// ============================================================

$end_time = microtime(true);
$execution_time = round($end_time - $start_time, 2);

echo "Media Added: {$media_added}\n";
echo "Media Removed: {$media_removed}\n";
echo "Media Skipped (duplicates/unknown): {$media_skipped}\n";
echo "Execution Time: {$execution_time}s\n";
