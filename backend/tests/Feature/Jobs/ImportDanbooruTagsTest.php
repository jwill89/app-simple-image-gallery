<?php

declare(strict_types=1);

use App\Jobs\ImportDanbooruTags;
use App\Models\Media;
use App\Services\Danbooru\DanbooruTagger;
use App\Support\CacheGroup;
use App\Support\ResponseCache;
use App\Support\TagImports;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Contract\DanbooruStub;
use Tests\Support\ContractFixtureSeeder;

use function Pest\Laravel\seed;

// The queued Danbooru import and gallery:import-tags, against the contract's
// Danbooru stub: media 1 is found by MD5 (post 1001), and media 16 is answered
// 429 (see tests/Fixtures/contract.sql).

beforeEach(function (): void {
    seed(ContractFixtureSeeder::class);
    config(['gallery.danbooru.login' => 'contract', 'gallery.danbooru.api_key' => 'contract-key', 'gallery.url' => 'https://gallery.test']);
    DanbooruStub::install(up: true);
});

test('an import applies the post\'s tags as the endpoint does, and invalidates media and tags', function (): void {
    $cache = app(ResponseCache::class);
    $cache->remember(CacheGroup::Tags, 'probe', 60, static fn(): array => []);

    new ImportDanbooruTags(Media::query()->findOrFail(1))->handle(app(DanbooruTagger::class), app(TagImports::class));

    expect(Media::query()->findOrFail(1)->sortedTags()->pluck('tag_name')->all())
        ->toBe(['animal', 'blue sky', 'cat', 'new thing', 'outdoors', 'artist one', 'brand new hero', 'hero', 'some series'])
        ->and($cache->remember(CacheGroup::Tags, 'probe', 60, static fn(): array => [])->headers->get('X-Cache'))->toBe('MISS');
});

test('imports are rate-limited, and one Danbooru answers 429 waits a minute and goes again', function (): void {
    $job = new ImportDanbooruTags(Media::query()->findOrFail(16))->withFakeQueueInteractions();

    expect($job->middleware())->toEqual([new RateLimited('danbooru')])
        ->and($job->retryUntil()->getTimestamp())->toBeGreaterThan(now()->addHours(23)->getTimestamp());

    $job->handle(app(DanbooruTagger::class), app(TagImports::class));

    $job->assertReleased(delay: 60);
});

test('gallery:import-tags queues every media item, or the one asked for', function (): void {
    Queue::fake();

    command('gallery:import-tags')->assertSuccessful();
    Queue::assertPushed(ImportDanbooruTags::class, Media::query()->count());

    command('gallery:import-tags 6')->expectsOutput('Queued a Danbooru import for media #6.')->assertSuccessful();
    Queue::assertPushed(ImportDanbooruTags::class, static fn(ImportDanbooruTags $job): bool => $job->media->media_id === 6);

    command('gallery:import-tags 999')->expectsOutput('Media #999 is not in the gallery.')->assertFailed();
});

test('gallery:import-tags refuses to run without Danbooru credentials', function (): void {
    config(['gallery.danbooru.api_key' => '']);
    Queue::fake();

    expect(Artisan::call('gallery:import-tags'))->toBe(1);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});
