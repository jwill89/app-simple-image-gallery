<?php

declare(strict_types=1);

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\Support\ContractFixture;
use Tests\Support\ContractFixtureSeeder;

use function Pest\Laravel\getJson;
use function Pest\Laravel\seed;

// Danbooru's firewall (Cloudflare) refusing the server, which the recordings
// don't show: their stub refuses in JSON, as the API itself does. A block is
// an HTML 403, and is reported as one, with Cloudflare's Ray ID for Danbooru's
// admins, rather than as rejected credentials.

const BLOCK_RAY = 'a549447adcbacc98-EWR';

/** Danbooru answering every call with $response. */
function danbooruAnswers(PromiseInterface $response): void
{
    Http::preventStrayRequests();
    Http::fake(['danbooru.donmai.us/*' => $response]);
}

function cloudflareBlockPage(): PromiseInterface
{
    return Http::response(
        '<!DOCTYPE html><html><head><title>Attention Required! | Cloudflare</title></head>'
        . '<body><h1>Sorry, you have been blocked</h1></body></html>',
        403,
        ['Content-Type' => 'text/html; charset=UTF-8', 'Server' => 'cloudflare', 'CF-Ray' => BLOCK_RAY],
    );
}

beforeEach(function (): void {
    seed(ContractFixtureSeeder::class);
    config([
        'gallery.allowed_origins' => ['https://gallery.test'],
        'cors.allowed_origins' => ['https://gallery.test'],
        'gallery.url' => 'https://gallery.test',
        'gallery.danbooru.login' => 'contract',
        'gallery.danbooru.api_key' => 'contract-key',
        'gallery.danbooru.user_id' => '123',
    ]);
});

test('every call names the account in the User-Agent, as Danbooru asks', function (): void {
    danbooruAnswers(Http::response([], 200));

    asAdmin()->postJson('/api/media/1/danbooru-tags');

    Http::assertSent(static fn(Request $request): bool => $request->header('User-Agent') === ['GalleryTagImporter/1.0; user #123']);
    Http::assertNotSent(static fn(Request $request): bool => $request->header('User-Agent') !== ['GalleryTagImporter/1.0; user #123']);
});

test('without a user ID, the User-Agent is the app alone', function (): void {
    config(['gallery.danbooru.user_id' => '']);
    danbooruAnswers(Http::response([], 200));

    asAdmin()->postJson('/api/media/1/danbooru-tags');

    Http::assertSent(static fn(Request $request): bool => $request->header('User-Agent') === ['GalleryTagImporter/1.0']);
});

test('a firewall block is 503 DanbooruBlocked, quoting the Ray ID, not rejected credentials', function (): void {
    danbooruAnswers(cloudflareBlockPage());

    $response = asAdmin()->postJson('/api/media/1/danbooru-tags')
        ->assertStatus(503)
        ->assertJsonPath('error', 'DanbooruBlocked');

    expect($response->json('message'))->toContain('firewall')->toContain('Cloudflare Ray ID ' . BLOCK_RAY);
    Http::assertSent(static fn(Request $request): bool => str_contains($request->url(), 'md5'));
});

test('a block without a user ID set says to set one', function (): void {
    config(['gallery.danbooru.user_id' => '']);
    danbooruAnswers(cloudflareBlockPage());

    $response = asAdmin()->postJson('/api/media/1/danbooru-tags')
        ->assertStatus(503)
        ->assertJsonPath('error', 'DanbooruBlocked');

    expect($response->json('message'))->toBe(
        'Danbooru\'s firewall (Cloudflare) blocked this server\'s request before it reached the API, so the '
        . 'credentials were never checked. Set DANBOORU_USER_ID in the server .env to the account\'s numeric '
        . 'user ID, which Danbooru asks API clients to name in their User-Agent.',
    );
});

test('a 403 from the API itself, in JSON, is still rejected credentials', function (): void {
    danbooruAnswers(Http::response(['success' => false, 'message' => 'Access denied'], 403));

    asAdmin()->postJson('/api/media/1/danbooru-tags')
        ->assertStatus(500)
        ->assertJsonPath('error', 'DanbooruAuthFailed');
});

test('an upload while blocked still adds the file, and its lookup reports why it has no tags', function (): void {
    danbooruAnswers(cloudflareBlockPage());
    $root = sys_get_temp_dir() . '/gallery-blocked-test-' . bin2hex(random_bytes(4));
    config(['gallery.media_path' => "{$root}/media"]);
    File::ensureDirectoryExists("{$root}/media/full");
    File::copy(ContractFixture::path('uploads/tiny.png'), "{$root}/tiny.png");
    // No ffmpeg, as on a host without it; the thumbnail isn't what's tested.
    Process::fake(['*' => Process::result(errorOutput: 'command not found', exitCode: 127)]);

    try {
        // The queue runs inline here, so the queued lookup is done on return.
        $response = asAdmin()->post('/api/media', [
            'files' => [new UploadedFile("{$root}/tiny.png", 'tiny.png', 'image/png', null, true)],
            'fetch_tags' => '1',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('total_uploaded', 1)
            ->assertJsonPath('results.0.status', 'success')
            ->assertJsonPath('results.0.tags_status', 'queued');
        $status = getJson('/api/media/tag-imports?ids=' . $response->json('results.0.id'))->json('0');
        expect($status['status'])->toBe('failed')
            ->and($status['error'])->toContain('Cloudflare Ray ID ' . BLOCK_RAY);
    } finally {
        File::deleteDirectory($root);
    }
});
