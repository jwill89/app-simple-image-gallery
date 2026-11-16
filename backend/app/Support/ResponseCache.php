<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Cached JSON responses for the busy reads, with an X-Cache: HIT or MISS header.
 *
 * Entries belong to a group, and a write invalidates whole groups. The file
 * store has no cache tags, so each group has a version number that is part of
 * every key in it: bump() moves the version on, and the old entries are never
 * read again and expire with their TTL.
 */
final class ResponseCache
{
    /** Lists and per-item lookups (seconds). */
    public const int TTL_SHORT = 30;

    /** Aggregates: tag lists, totals, implications (seconds). */
    public const int TTL_MEDIUM = 60;

    /** @param Closure(): mixed $generate The data to encode as the response body. */
    public function remember(CacheGroup $group, string $key, int $ttl, Closure $generate): JsonResponse
    {
        $cacheKey = 'response:' . $group->value . ':v' . $this->version($group) . ':' . md5($key);

        $json = Cache::get($cacheKey);
        if (is_string($json)) {
            return JsonResponse::fromJsonString($json, 200, ['X-Cache' => 'HIT']);
        }

        $json = json_encode($generate(), JSON_THROW_ON_ERROR);
        Cache::put($cacheKey, $json, $ttl);

        return JsonResponse::fromJsonString($json, 200, ['X-Cache' => 'MISS']);
    }

    /**
     * remember() for a page of a longer list: the generator gives the page's
     * body and how many items the whole list holds, which goes out as
     * X-Total-Count and is cached with the body.
     *
     * @param Closure(): array{0: mixed, 1: int} $generate
     */
    public function rememberPage(CacheGroup $group, string $key, int $ttl, Closure $generate): JsonResponse
    {
        $cacheKey = 'response:' . $group->value . ':v' . $this->version($group) . ':page:' . md5($key);
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && is_string($cached['json'] ?? null) && is_int($cached['total'] ?? null)) {
            return JsonResponse::fromJsonString($cached['json'], 200, ['X-Cache' => 'HIT', 'X-Total-Count' => (string) $cached['total']]);
        }

        [$body, $total] = $generate();
        $json = json_encode($body, JSON_THROW_ON_ERROR);
        Cache::put($cacheKey, ['json' => $json, 'total' => $total], $ttl);

        return JsonResponse::fromJsonString($json, 200, ['X-Cache' => 'MISS', 'X-Total-Count' => (string) $total]);
    }

    /** Invalidate every cached response in these groups. */
    public function bump(CacheGroup ...$groups): void
    {
        foreach ($groups as $group) {
            Cache::increment($this->versionKey($group));
        }
    }

    private function version(CacheGroup $group): int
    {
        $version = Cache::get($this->versionKey($group), 0);

        return is_int($version) ? $version : (int) (is_numeric($version) ? $version : 0);
    }

    private function versionKey(CacheGroup $group): string
    {
        return 'response:' . $group->value . ':version';
    }
}
