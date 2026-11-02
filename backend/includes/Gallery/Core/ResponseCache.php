<?php

namespace Gallery\Core;

/**
 * ResponseCache class
 *
 * File-based response cache for API GET endpoints.
 * Stores JSON response strings in flat files keyed by group and identifier.
 * Supports group-based invalidation so that mutations can efficiently
 * clear only the affected subset of cached responses.
 *
 * Cache files are stored as: {cacheDir}/{group}_{md5(key)}.cache
 * File format: first line is the expiry Unix timestamp, remaining lines are the JSON payload.
 */
class ResponseCache
{
    /** Short TTL for paginated lists and per-item lookups (seconds). */
    public const int TTL_SHORT = 30;

    /** Medium TTL for aggregate data like tag lists, totals, implications (seconds). */
    public const int TTL_MEDIUM = 60;

    private readonly string $cacheDir;
    private bool $enabled;
    private static ?self $instance = null;

    public static function getInstance(): self
    {
        self::$instance ??= new self();
        return self::$instance;
    }

    private function __construct()
    {
        // Resolve cache directory relative to the includes/ tree, landing at {project_root}/cache/api/
        $this->cacheDir = dirname(__DIR__, 3) . '/cache/api/';
        $this->enabled = true;

        if (!is_dir($this->cacheDir)) {
            $this->enabled = @mkdir($this->cacheDir, 0o755, true);
        }
    }

    /**
     * Retrieve a cached JSON string.
     *
     * @param string $group Cache group (e.g. 'media', 'tags').
     * @param string $key   Unique key within the group.
     *
     * @return string|null The cached JSON string, or null on miss/expiry.
     */
    public function get(string $group, string $key): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        $path = $this->path($group, $key);
        $raw = @file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        $nl = strpos($raw, "\n");

        if ($nl === false) {
            @unlink($path);
            return null;
        }

        $expiresAt = (int) substr($raw, 0, $nl);

        if ($expiresAt < time()) {
            @unlink($path);
            return null;
        }

        return substr($raw, $nl + 1);
    }

    /**
     * Store a JSON string in the cache.
     *
     * @param string $group Cache group.
     * @param string $key   Unique key within the group.
     * @param string $json  The JSON-encoded response body to cache.
     * @param int    $ttl   Time-to-live in seconds.
     */
    public function set(string $group, string $key, string $json, int $ttl): void
    {
        if (!$this->enabled) {
            return;
        }

        $content = (time() + $ttl) . "\n" . $json;
        @file_put_contents($this->path($group, $key), $content, LOCK_EX);

        // Occasionally sweep expired files so entries whose keys are never
        // requested again (and thus never lazily evicted on read) don't pile up.
        if (random_int(1, 100) === 1) {
            $this->gc();
        }
    }

    /**
     * Delete all expired cache files. Cheap, best-effort housekeeping run
     * probabilistically from set() to keep the cache directory bounded.
     */
    private function gc(): void
    {
        $files = @glob($this->cacheDir . '*.cache');
        if ($files === false || $files === []) {
            return;
        }

        $now = time();
        foreach ($files as $f) {
            $handle = @fopen($f, 'rb');
            if ($handle === false) {
                continue;
            }
            $firstLine = fgets($handle);
            fclose($handle);

            if ($firstLine !== false && (int) $firstLine < $now) {
                @unlink($f);
            }
        }
    }

    /**
     * Invalidate all cache entries belonging to one or more groups.
     *
     * @param string ...$groups One or more group names to invalidate.
     */
    public function invalidateGroups(string ...$groups): void
    {
        if (!$this->enabled) {
            return;
        }

        foreach ($groups as $group) {
            $files = @glob($this->cacheDir . $group . '_*.cache');
            if ($files !== false) {
                foreach ($files as $f) {
                    @unlink($f);
                }
            }
        }
    }

    /**
     * Flush the entire cache.
     */
    public function flush(): void
    {
        if (!$this->enabled) {
            return;
        }

        $files = @glob($this->cacheDir . '*.cache');
        if ($files !== false) {
            foreach ($files as $f) {
                @unlink($f);
            }
        }
    }

    /**
     * Build the filesystem path for a cache entry.
     */
    private function path(string $group, string $key): string
    {
        return $this->cacheDir . $group . '_' . md5($key) . '.cache';
    }
}
