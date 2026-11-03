<?php

declare(strict_types=1);

use App\Support\CacheGroup;
use App\Support\ResponseCache;

use function Pest\Laravel\travel;

function cachedCount(CacheGroup $group, string $key, int &$calls): string
{
    $response = app(ResponseCache::class)->remember($group, $key, ResponseCache::TTL_SHORT, static function () use (&$calls): array {
        $calls++;

        return ['count' => $calls];
    });

    return $response->headers->get('X-Cache') . ' ' . $response->getContent();
}

test('a cached response is a MISS, then a HIT with the same body', function (): void {
    $calls = 0;

    expect(cachedCount(CacheGroup::Media, 'count', $calls))->toBe('MISS {"count":1}')
        ->and(cachedCount(CacheGroup::Media, 'count', $calls))->toBe('HIT {"count":1}')
        ->and($calls)->toBe(1);
});

test('bumping a group invalidates its responses and no others', function (): void {
    $media = 0;
    $tags = 0;
    cachedCount(CacheGroup::Media, 'count', $media);
    cachedCount(CacheGroup::Tags, 'all', $tags);

    app(ResponseCache::class)->bump(CacheGroup::Media);

    expect(cachedCount(CacheGroup::Media, 'count', $media))->toBe('MISS {"count":2}')
        ->and(cachedCount(CacheGroup::Tags, 'all', $tags))->toBe('HIT {"count":1}');
});

test('bumping several groups at once invalidates each', function (): void {
    $media = 0;
    $tags = 0;
    cachedCount(CacheGroup::Media, 'count', $media);
    cachedCount(CacheGroup::Tags, 'all', $tags);

    app(ResponseCache::class)->bump(CacheGroup::Media, CacheGroup::Tags);

    expect(cachedCount(CacheGroup::Media, 'count', $media))->toStartWith('MISS')
        ->and(cachedCount(CacheGroup::Tags, 'all', $tags))->toStartWith('MISS');
});

test('a response expires after its TTL', function (): void {
    $calls = 0;
    cachedCount(CacheGroup::Media, 'count', $calls);

    travel(ResponseCache::TTL_SHORT + 1)->seconds();

    expect(cachedCount(CacheGroup::Media, 'count', $calls))->toBe('MISS {"count":2}');
});
