<?php

namespace Gallery\Core;

use PDO;
use Gallery\Repository\DanbooruRulesRepository;

/**
 * DanbooruTagger
 *
 * Looks up media on the Danbooru API, then creates/resolves gallery tags
 * and links them to the media item. Reusable by both the cron script and
 * the upload controller.
 *
 * Lookup strategy:
 *   1. MD5 hash (fast, exact match)
 *   2. IQDB visual similarity (fallback for re-encoded files: webp, gif, etc.)
 */
class DanbooruTagger
{
    private const string API_BASE = 'https://danbooru.donmai.us';

    /** Minimum IQDB similarity score (0–100) to accept as a match. */
    private const int IQDB_SCORE_THRESHOLD = 90;

    /**
     * Why the last upstream call failed, when it wasn't simply "no match".
     * These separate a configuration problem from an empty result — the two
     * were previously reported identically as "not found on Danbooru".
     */
    public const string FAILURE_AUTH = 'auth';
    public const string FAILURE_RATE_LIMIT = 'rate_limit';
    public const string FAILURE_NETWORK = 'network';
    public const string FAILURE_UPSTREAM = 'upstream';
    private readonly string $login;
    private readonly string $apiKey;

    /** One of the FAILURE_* constants, or null when the last call was fine. */
    private ?string $lastFailure = null;

    /** Whether the import maps and DB caches have been warmed (see ensureInitialized()). */
    private bool $initialized = false;

    /** @var array<int, array{gallery_category_id: int, field: string}> danbooru_category_id => mapping */
    private array $categoryMap = [];

    /** @var array<string, string> danbooru_tag => gallery_tag */
    private array $tagNameMap = [];

    /** @var array<string, int> category_name => category_id */
    private array $categoryCache = [];

    /** @var array<string, int> lowercase tag_name => tag_id */
    private array $tagCache = [];

    /** @var (callable(string): void)|null Optional callback for debug/diagnostic output. */
    private $debugCallback;

    /**
     * Set a callback for debug output (e.g. for CLI verbose mode).
     * The callback receives a single string message.
     */
    public function setDebugCallback(callable $callback): void
    {
        $this->debugCallback = $callback;
    }

    private function debug(string $message): void
    {
        if ($this->debugCallback !== null) {
            ($this->debugCallback)($message);
        }
    }

    public function __construct(private readonly PDO $db, private readonly DanbooruRulesRepository $rulesRepository)
    {
        $this->login = Configuration::getDanbooruLogin();
        $this->apiKey = Configuration::getDanbooruApiKey();
    }

    /**
     * Lazily load the import maps and warm the category/tag caches from the
     * database. Construction stays cheap so this tagger can be injected
     * directly into controllers without paying four DB queries (including a
     * full scan of the tags table) on requests that never import tags.
     * Called at the start of every public import method; runs at most once.
     */
    private function ensureInitialized(): void
    {
        if ($this->initialized) {
            return;
        }
        $this->initialized = true;

        $this->categoryMap = $this->rulesRepository->getCategoryMapWithFields();
        $this->tagNameMap = $this->rulesRepository->getTagMapArray();

        $stmt = $this->db->query('SELECT category_id, category_name FROM tag_categories');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->categoryCache[$row['category_name']] = (int) $row['category_id'];
        }

        $stmt = $this->db->query('SELECT tag_id, tag_name FROM tags');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->tagCache[strtolower($row['tag_name'])] = (int) $row['tag_id'];
        }
    }

    /**
     * Look up media on Danbooru and apply any found tags to the media item.
     *
     * Tries MD5 hash first, then falls back to IQDB visual similarity
     * if a file path is provided and the MD5 lookup found nothing.
     *
     * @param int         $mediaId  The gallery media_id.
     * @param string      $md5      The MD5 hash of the file.
     * @param string|null $fileName The media file name (enables IQDB fallback via public URL).
     *
     * @return array{found: bool, tags_created: int, tags_applied: int, method: string, failure: string|null}
     */
    public function importTagsForMedia(int $mediaId, string $md5, ?string $fileName = null): array
    {
        $this->ensureInitialized();

        $stats = ['found' => false, 'tags_created' => 0, 'tags_applied' => 0, 'method' => 'none', 'failure' => null];
        $this->lastFailure = null;

        // 1. Try exact MD5 lookup
        $posts = $this->apiGet('/posts.json?tags=md5:' . urlencode($md5));
        $post = null;

        if ($posts !== null && $posts !== []) {
            $post = $posts[0];
            $stats['method'] = 'md5';
            $this->debug("  MD5 match: post #{$post['id']}");
        } else {
            $this->debug("  MD5 miss");
        }

        // 2. Fall back to IQDB visual similarity if MD5 missed
        if ($post === null && $fileName !== null) {
            $this->debug("  Trying IQDB fallback...");
            $post = $this->iqdbLookup($fileName);
            if ($post !== null) {
                $stats['method'] = 'iqdb';
            }
        }

        if ($post === null) {
            $stats['failure'] = $this->lastFailure;
            return $stats;
        }

        $stats['found'] = true;
        $this->applyPostTags($mediaId, $post, $stats);

        return $stats;
    }

    /**
     * Import tags from a specific Danbooru post ID.
     *
     * @param int $mediaId       The gallery media_id.
     * @param int $danbooruPostId The Danbooru post ID to import from.
     *
     * @return array{found: bool, tags_created: int, tags_applied: int, method: string, failure: string|null}
     */
    public function importTagsFromPost(int $mediaId, int $danbooruPostId): array
    {
        $this->ensureInitialized();

        $stats = ['found' => false, 'tags_created' => 0, 'tags_applied' => 0, 'method' => 'none', 'failure' => null];
        $this->lastFailure = null;

        $post = $this->apiGet('/posts/' . $danbooruPostId . '.json');

        if (!is_array($post) || !is_int($post['id'] ?? null)) {
            $this->debug("  Post #{$danbooruPostId} not found");
            $stats['failure'] = $this->lastFailure;
            return $stats;
        }

        $stats['found'] = true;
        $stats['method'] = 'post_id';
        $this->debug("  Fetched post #{$danbooruPostId}");

        $this->applyPostTags($mediaId, $post, $stats);

        return $stats;
    }

    /**
     * Extract tags from a Danbooru post and apply them to the given media item.
     *
     * @param int $mediaId The gallery media_id.
     * @param array<string, mixed> $post The decoded Danbooru post object.
     * @param array{found: bool, tags_created: int, tags_applied: int, method: string, failure: string|null} $stats Stats array to update.
     * @throws \Throwable If applying the tag set fails; the transaction is rolled back first.
     */
    private function applyPostTags(int $mediaId, array $post, array &$stats): void
    {
        // Build categorized tag map only for categories that have import rules.
        $categorizedTags = [];
        foreach ($this->categoryMap as $danbooruCatId => $mapping) {
            $field = $mapping['field'];
            if (!is_string($post[$field] ?? null) || $post[$field] === '') {
                continue;
            }
            foreach (explode(' ', $post[$field]) as $t) {
                if ($t !== '') {
                    $categorizedTags[$t] = $danbooruCatId;
                }
            }
        }

        $linkStmt = $this->db->prepare(
            'INSERT OR IGNORE INTO media_tags (media_id, tag_id) VALUES (:iid, :tid)'
        );

        // Apply all tags atomically: either the whole post's tag set lands or
        // none of it does, and we avoid per-statement commit overhead.
        $this->db->beginTransaction();

        try {
            foreach ($categorizedTags as $danbooruTag => $danbooruCategory) {
                $ourCategoryId = $this->categoryMap[$danbooruCategory]['gallery_category_id'];

                if (!in_array($ourCategoryId, array_values($this->categoryCache), true)) {
                    continue;
                }

                $galleryTagName = $this->tagNameMap[$danbooruTag] ?? str_replace('_', ' ', $danbooruTag);
                $key = strtolower($galleryTagName);

                if (isset($this->tagCache[$key])) {
                    $tagId = $this->tagCache[$key];
                } else {
                    $insertStmt = $this->db->prepare('INSERT OR IGNORE INTO tags (tag_name, category_id) VALUES (:name, :cat)');
                    $insertStmt->execute([':name' => $galleryTagName, ':cat' => $ourCategoryId]);

                    // rowCount() — NOT lastInsertId() — tells us whether a row was
                    // actually inserted. After an ignored INSERT OR IGNORE,
                    // lastInsertId() returns a stale value from a prior insert,
                    // which would link the wrong tag and inflate tags_created.
                    if ($insertStmt->rowCount() === 1) {
                        $tagId = (int) $this->db->lastInsertId();
                        $stats['tags_created']++;
                    } else {
                        // Tag already exists — look up its id (case-insensitive).
                        $lookup = $this->db->prepare('SELECT tag_id FROM tags WHERE tag_name = :name COLLATE NOCASE');
                        $lookup->execute([':name' => $galleryTagName]);
                        $row = $lookup->fetch(PDO::FETCH_ASSOC);

                        if ($row === false) {
                            continue;
                        }

                        $tagId = (int) $row['tag_id'];
                    }

                    $this->tagCache[$key] = $tagId;
                }

                $linkStmt->execute([':iid' => $mediaId, ':tid' => $tagId]);
                $stats['tags_applied']++;
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Check whether Danbooru credentials are configured.
     */
    public static function isConfigured(): bool
    {
        return Configuration::getDanbooruLogin() !== ''
            && Configuration::getDanbooruApiKey() !== '';
    }

    /**
     * Search IQDB for a visually similar post using the image's public URL.
     *
     * Requires GALLERY_URL to be set in .env so a public URL can be
     * constructed for Danbooru's IQDB service to fetch.
     *
     * @param string $fileName The media file name (e.g. "image.webp").
     * @return array<string, mixed>|null The full Danbooru post array, or null.
     */
    private function iqdbLookup(string $fileName): ?array
    {
        // IQDB only works with image files — skip videos
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'tif', 'avif'];
        if (!in_array($ext, $imageExtensions, true)) {
            $this->debug("  IQDB skip: .{$ext} is not a supported image format");
            return null;
        }

        $galleryUrl = Configuration::getGalleryUrl();
        if ($galleryUrl === '') {
            $this->debug("  IQDB skip: GALLERY_URL is not configured in .env");
            return null;
        }

        $imageUrl = $galleryUrl . '/media/full/' . rawurlencode($fileName);
        $this->debug("  IQDB query URL: {$imageUrl}");

        $url = self::API_BASE . '/iqdb_queries.json'
            . '?login=' . urlencode($this->login)
            . '&api_key=' . urlencode($this->apiKey)
            . '&search[url]=' . urlencode($imageUrl);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_USERAGENT => 'GalleryTagImporter/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($curlError !== '') {
            $this->debug("  IQDB curl error: {$curlError}");
            return null;
        }

        if ($httpCode !== 200 || !is_string($response)) {
            $this->debug("  IQDB HTTP {$httpCode}" . (is_string($response) ? ": " . substr($response, 0, 200) : ''));
            return null;
        }

        $results = json_decode($response, true);
        if ($results === null) {
            $this->debug("  IQDB response not valid JSON: " . substr($response, 0, 200));
            return null;
        }

        if (!is_array($results) || $results === []) {
            $this->debug("  IQDB returned empty results");
            return null;
        }

        // Results are sorted by score descending; take the best match
        $best = $results[0];
        $score = (float) ($best['score'] ?? 0);
        $postId = (int) ($best['post']['id'] ?? 0);

        $this->debug("  IQDB best match: post #{$postId}, score {$score}"
            . (isset($best['post']['md5']) ? ", md5 {$best['post']['md5']}" : ''));

        if ($score < self::IQDB_SCORE_THRESHOLD) {
            $this->debug("  IQDB score {$score} below threshold " . self::IQDB_SCORE_THRESHOLD);
            return null;
        }

        if ($postId === 0) {
            $this->debug("  IQDB match has no post ID");
            return null;
        }

        // IQDB returns a summary post object — fetch the full post for tag strings
        $fullPost = $this->apiGet('/posts/' . $postId . '.json');
        if (!is_array($fullPost)) {
            $this->debug("  IQDB failed to fetch full post #{$postId}");
            return null;
        }

        $this->debug("  IQDB matched post #{$postId} (score {$score})");
        return $fullPost;
    }

    /**
     * Make an authenticated GET request to the Danbooru API.
     *
     * @return array<mixed>|null The decoded JSON response (list or object), or null.
     */
    private function apiGet(string $path): ?array
    {
        $url = self::API_BASE . $path;
        $separator = str_contains($url, '?') ? '&' : '?';
        $url .= $separator . 'login=' . urlencode($this->login) . '&api_key=' . urlencode($this->apiKey);

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
        $curlError = curl_error($ch);
        unset($ch);

        if ($httpCode === 200 && is_string($response)) {
            $this->lastFailure = null;
            return json_decode($response, true);
        }

        // Every non-200 used to collapse into `null`, which the caller reported
        // as "not found on Danbooru" — so an expired API key, a rate limit and a
        // genuine miss were indistinguishable to the user. Record why instead.
        $this->lastFailure = match (true) {
            $curlError !== ''     => self::FAILURE_NETWORK,
            $httpCode === 401,
            $httpCode === 403     => self::FAILURE_AUTH,
            $httpCode === 429     => self::FAILURE_RATE_LIMIT,
            $httpCode >= 500      => self::FAILURE_UPSTREAM,
            default               => null,   // 404 etc. — a real miss
        };
        $this->debug("  API {$path} → HTTP {$httpCode}"
            . ($curlError !== '' ? " (curl: {$curlError})" : '')
            . ($this->lastFailure !== null ? " [{$this->lastFailure}]" : ''));

        return null;
    }
}
