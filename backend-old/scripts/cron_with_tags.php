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
use Gallery\Core\Configuration;
use Gallery\Core\DatabaseConnection;
use Gallery\Structure\Media;

// Set Time Limit for Script, 30 minutes
set_time_limit(1800);

// Set Start Time
$start_time = microtime(true);

// ============================================================
// Danbooru Configuration (loaded from .env via Configuration)
// ============================================================
const DANBOORU_API_BASE = 'https://danbooru.donmai.us';
define('DANBOORU_LOGIN', Configuration::getDanbooruLogin());
define('DANBOORU_API_KEY', Configuration::getDanbooruApiKey());

const DANBOORU_CATEGORY_MAP = [
    0 => 'General',     // General -> General
    1 => 'Artist',      // Artist -> Artist
    3 => 'Source',      // Copyright -> Source
    4 => 'Character',   // Character -> Character
];

const TAG_NAME_MAP = [
    '1boy'           => 'one man',
    '1girl'          => 'one woman',
    'multiple_boys'  => 'multiple men',
    'multiple_girls' => 'multiple women',
    '2boys'          => 'two men',
    '2girls'         => 'two women',
];

// ============================================================
// Danbooru Helper Functions
// ============================================================

/**
 * @return array<mixed>|null Decoded JSON response, or null on failure.
 */
function danbooruGet(string $url): ?array
{
    $separator = str_contains($url, '?') ? '&' : '?';
    $url .= $separator . 'login=' . urlencode(DANBOORU_LOGIN) . '&api_key=' . urlencode(DANBOORU_API_KEY);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'GalleryTagImporter/1.0',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($httpCode === 200 && is_string($response)) {
        return json_decode($response, true);
    }

    return null;
}

/**
 * @param array<string, int> $categoryCache category_name => category_id
 * @param array<string, int> $tagCache lowercase tag_name => tag_id
 * @param array{found: int, tags_created: int, tags_applied: int} $tagStats
 */
function importTagsForMedia(int $mediaId, string $md5, PDO $db, array &$categoryCache, array &$tagCache, array &$tagStats): void
{
    $url = DANBOORU_API_BASE . '/posts.json?tags=md5:' . urlencode($md5);
    $posts = danbooruGet($url);

    if ($posts === null || $posts === []) {
        return;
    }

    $post = $posts[0];
    $tagStats['found']++;

    $categorizedTags = [];

    // Danbooru tag-string field => Danbooru category id. Later fields win when a
    // tag appears in more than one.
    $fieldCategories = [
        'tag_string_artist' => 1,
        'tag_string_character' => 4,
        'tag_string_copyright' => 3,
        'tag_string_general' => 0,
    ];
    foreach ($fieldCategories as $field => $danbooruCategory) {
        $tagString = $post[$field] ?? '';
        if (!is_string($tagString)) {
            continue;
        }
        foreach (explode(' ', $tagString) as $t) {
            if ($t !== '') {
                $categorizedTags[$t] = $danbooruCategory;
            }
        }
    }

    // Prepared statement for media_tags
    static $mediaTagStmt = null;
    $mediaTagStmt ??= $db->prepare('INSERT OR IGNORE INTO media_tags (media_id, tag_id) VALUES (:mid, :tid)');

    foreach ($categorizedTags as $danbooruTag => $danbooruCategory) {
        // $danbooruCategory is always one of the DANBOORU_CATEGORY_MAP keys
        // (set from the hard-coded category blocks above), so no guard is needed.
        $ourCategoryName = DANBOORU_CATEGORY_MAP[$danbooruCategory];
        $ourCategoryId = $categoryCache[$ourCategoryName] ?? null;
        if ($ourCategoryId === null) {
            continue;
        }

        // Map tag name
        if (isset(TAG_NAME_MAP[$danbooruTag])) {
            $galleryTagName = TAG_NAME_MAP[$danbooruTag];
        } else {
            $galleryTagName = str_replace('_', ' ', $danbooruTag);
        }

        // Get or create tag in DB
        $key = strtolower($galleryTagName);
        if (isset($tagCache[$key])) {
            $tagId = $tagCache[$key];
        } else {
            $stmt = $db->prepare('INSERT OR IGNORE INTO tags (tag_name, category_id) VALUES (:name, :cat)');
            $stmt->execute([':name' => $galleryTagName, ':cat' => $ourCategoryId]);

            if ($db->lastInsertId() > 0) {
                $tagId = (int) $db->lastInsertId();
                $tagStats['tags_created']++;
            } else {
                $stmt = $db->prepare('SELECT tag_id FROM tags WHERE tag_name = :name COLLATE NOCASE');
                $stmt->execute([':name' => $galleryTagName]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row === false) {
                    continue;
                }
                $tagId = (int) $row['tag_id'];
            }
            $tagCache[$key] = $tagId;
        }

        $mediaTagStmt->execute([':mid' => $mediaId, ':tid' => $tagId]);
        $tagStats['tags_applied']++;
    }
}

// ============================================================
// Setup Collection
// ============================================================
$container = new \DI\ContainerBuilder()->addDefinitions(__DIR__ . '/../api/dependencies.php')->build();
$media_collection = $container->get(MediaCollection::class);

$media_dir = MediaCollection::MEDIA_DIRECTORY;
$media_dir_full = MediaCollection::MEDIA_DIRECTORY_FULL;

// Supported file extensions
$supported_extensions = [
    'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'tif', 'avif',
    'mp4', 'webm', 'mov', 'avi', 'mkv', 'flv', 'wmv', 'm4v',
];

// ============================================================
// Scan Input Folder
// ============================================================

$files_in_folder = array_values(array_filter(
    scandir($media_dir),
    static fn($item) => !is_dir($media_dir . $item)
));

// ============================================================
// Load Database Records & Build Lookup Maps
// ============================================================

$all_summaries = $media_collection->getAllSummary();

$known_hashes = [];
$known_filenames = [];

foreach ($all_summaries as $item) {
    $known_hashes[$item['hash']] = true;
    $known_filenames[$item['file_name']] = true;
}

// ============================================================
// Initialize Counters
// ============================================================
$media_added = 0;
$media_removed = 0;
$media_skipped = 0;

// Tag import stats
$tagStats = [
    'found' => 0,
    'tags_created' => 0,
    'tags_applied' => 0,
];

// ============================================================
// Remove Orphaned Database Entries
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

unset($all_summaries);

// ============================================================
// Prepare Tag Caches for Danbooru Import
// ============================================================

$categoryCache = [];
$catStmt = $db->query('SELECT category_id, category_name FROM tag_categories');
while ($row = $catStmt->fetch(PDO::FETCH_ASSOC)) {
    $categoryCache[$row['category_name']] = (int) $row['category_id'];
}

$tagCache = [];
$tagStmt = $db->query('SELECT tag_id, tag_name FROM tags');
while ($row = $tagStmt->fetch(PDO::FETCH_ASSOC)) {
    $tagCache[strtolower($row['tag_name'])] = (int) $row['tag_id'];
}

// ============================================================
// Process New Media (with Danbooru tag import for images)
// ============================================================

$newImageMedia = []; // Track newly added images for tag import

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
        $savedId = $media_collection->save($media);
        if ($savedId !== 0) {
            // Move file to the full-size directory
            rename($file_path, $media_dir_full . $file_name);

            // Add to lookup maps so subsequent files in this batch are checked
            $known_hashes[$file_md5] = true;
            $known_filenames[$file_name] = true;

            // Track images for Danbooru tag import
            if ($media_type === 'image') {
                $newImageMedia[] = ['media_id' => $savedId, 'hash' => $file_md5];
            }

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
// Import Danbooru Tags for Newly Added Images
// ============================================================

if ($newImageMedia !== []) {
    $imageCount = count($newImageMedia);
    echo "Importing Danbooru tags for {$imageCount} new image(s)...\n";

    foreach ($newImageMedia as $img) {
        importTagsForMedia($img['media_id'], $img['hash'], $db, $categoryCache, $tagCache, $tagStats);
        // Rate limit: 1 second between Danbooru API calls
        usleep(1000000);
    }
}

// ============================================================
// Output Results
// ============================================================

$end_time = microtime(true);
$execution_time = round($end_time - $start_time, 2);

echo "Media Added: {$media_added}\n";
echo "Media Removed: {$media_removed}\n";
echo "Media Skipped (duplicates/unknown): {$media_skipped}\n";
echo "Danbooru: {$tagStats['found']} found, {$tagStats['tags_created']} tags created, {$tagStats['tags_applied']} tags applied\n";
echo "Execution Time: {$execution_time}s\n";
