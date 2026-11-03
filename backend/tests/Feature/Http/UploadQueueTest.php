<?php

declare(strict_types=1);

use App\Jobs\ImportDanbooruTags;
use App\Jobs\RebuildFromFile;
use App\Models\Media;
use App\Services\Danbooru\DanbooruTagger;
use App\Services\Media\MediaDerivative;
use App\Support\TagImports;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Contract\DanbooruStub;
use Tests\Support\ContractFixture;
use Tests\Support\ContractFixtureSeeder;

use function Pest\Laravel\getJson;
use function Pest\Laravel\seed;
use function Pest\Laravel\withHeaders;

// An upload stays quick: it adds the file, and queues what can be slow, the
// thumbnails (ffmpeg) and the Danbooru lookup, whose outcome
// GET /media/tag-imports reports to the upload page.

beforeEach(function (): void {
    seed(ContractFixtureSeeder::class);
    config([
        'gallery.danbooru.login' => 'contract',
        'gallery.danbooru.api_key' => 'contract-key',
        'gallery.danbooru.user_id' => '123',
        'gallery.url' => 'https://gallery.test',
        'gallery.media_path' => sys_get_temp_dir() . '/gallery-upload-queue-' . bin2hex(random_bytes(4)) . '/media',
    ]);
    File::ensureDirectoryExists(config()->string('gallery.media_path') . '/full');
});

afterEach(function (): void {
    File::deleteDirectory(dirname(config()->string('gallery.media_path')));
});

/**
 * Upload the fixture tiny.png, as the upload page sends it.
 *
 * @return TestResponse<Response>
 */
function uploadTinyPng(bool $fetchTags): TestResponse
{
    $copy = dirname(config()->string('gallery.media_path')) . '/tiny.png';
    File::copy(ContractFixture::path('uploads/tiny.png'), $copy);

    return withHeaders(adminHeaders())->post('/api/media', [
        'files' => [new UploadedFile($copy, 'tiny.png', 'image/png', null, true)],
        ...($fetchTags ? ['fetch_tags' => '1'] : []),
    ]);
}

test('an upload adds the file and queues its thumbnails and its Danbooru lookup', function (): void {
    Queue::fake();

    $response = uploadTinyPng(fetchTags: true)->assertCreated();

    $id = $response->json('results.0.id');
    expect($response->json())->toBe([
        'results' => [['file_name' => 'tiny.png', 'status' => 'success', 'id' => $id, 'hash' => md5_file(ContractFixture::path('uploads/tiny.png')), 'tags_status' => 'queued']],
        'total_uploaded' => 1,
        'total_duplicates' => 0,
        'total_failed' => 0,
    ]);
    Queue::assertPushed(RebuildFromFile::class, static fn(RebuildFromFile $job): bool => $job->media->media_id === $id && $job->derivative === MediaDerivative::Thumbnails);
    Queue::assertPushed(ImportDanbooruTags::class, static fn(ImportDanbooruTags $job): bool => $job->media->media_id === $id);
    getJson("/api/media/tag-imports?ids={$id}")->assertOk()->assertExactJson([['media_id' => $id, 'status' => 'queued', 'tags_applied' => 0, 'error' => null]]);
});

test('without fetch_tags no lookup is queued', function (): void {
    Queue::fake();

    uploadTinyPng(fetchTags: false)->assertCreated()->assertJsonMissingPath('results.0.tags_status');

    Queue::assertPushed(RebuildFromFile::class);
    Queue::assertNotPushed(ImportDanbooruTags::class);
});

test('asked for tags with Danbooru not set up, the file says so', function (): void {
    Queue::fake();
    config(['gallery.danbooru.api_key' => '']);

    uploadTinyPng(fetchTags: true)->assertCreated()
        ->assertJsonPath('results.0.status', 'success')
        ->assertJsonPath('results.0.tags_error', 'Danbooru is not set up on the server (DANBOORU_LOGIN and DANBOORU_API_KEY).');

    Queue::assertNotPushed(ImportDanbooruTags::class);
});

test('tag-imports reports a lookup that applied tags, and one that found no post', function (): void {
    DanbooruStub::install(up: true);

    // Media 1 is a post by its MD5; media 3 is on Danbooru by neither MD5 nor IQDB.
    new ImportDanbooruTags(Media::query()->findOrFail(1))->handle(app(DanbooruTagger::class), app(TagImports::class));
    new ImportDanbooruTags(Media::query()->findOrFail(3))->handle(app(DanbooruTagger::class), app(TagImports::class));

    $statuses = getJson('/api/media/tag-imports?ids=1,3,4')->assertOk()->json();

    expect($statuses[0])->toMatchArray(['media_id' => 1, 'status' => 'applied'])
        ->and($statuses[0]['tags_applied'])->toBeGreaterThan(0)
        ->and($statuses[1])->toBe(['media_id' => 3, 'status' => 'not_found', 'tags_applied' => 0, 'error' => null])
        ->and($statuses[2])->toBe(['media_id' => 4, 'status' => 'none', 'tags_applied' => 0, 'error' => null]);
});

test('tag-imports reports a failed lookup with its reason', function (): void {
    Http::preventStrayRequests();
    Http::fake(['danbooru.donmai.us/*' => Http::response('<html><title>Attention Required! | Cloudflare</title></html>', 403, ['Content-Type' => 'text/html', 'CF-Ray' => 'abc123-EWR'])]);

    new ImportDanbooruTags(Media::query()->findOrFail(2))->handle(app(DanbooruTagger::class), app(TagImports::class));

    $status = getJson('/api/media/tag-imports?ids=2')->assertOk()->json('0');
    expect($status)->toMatchArray(['media_id' => 2, 'status' => 'failed', 'tags_applied' => 0])
        ->and($status['error'])->toContain('Cloudflare Ray ID abc123-EWR');
});

test('tag-imports needs ids', function (): void {
    getJson('/api/media/tag-imports')->assertStatus(400)->assertJson(['error' => 'InvalidInput']);
    getJson('/api/media/tag-imports?ids=abc')->assertStatus(400);
});
