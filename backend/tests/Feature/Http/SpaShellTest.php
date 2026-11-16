<?php

declare(strict_types=1);

use App\Http\Controllers\SpaController;
use Tests\Support\ContractFixture;

use function Pest\Laravel\get;
use function Pest\Laravel\withHeaders;

// The SPA shell, beyond what its recordings show (contract recordings spa/*):
// it sets no cookies, it leaves /api to the API, a path that can only name a
// file is a 404 rather than the page, a trailing slash changes nothing, and
// storage:link points public/media at the media folder.

beforeEach(function (): void {
    config(['gallery.spa_index' => ContractFixture::path('spa/index.html')]);
});

test('a page sets no session or cookie', function (): void {
    get('/favorites')->assertOk()->assertHeaderMissing('Set-Cookie');
});

test('an /api path no route answers stays a JSON error, whatever the method', function (): void {
    get('/api/no-such-route')->assertNotFound()->assertJsonPath('type', problemType('NotFound'));
    config(['gallery.allowed_origins' => ['https://gallery.test']]);
    withHeaders(['Origin' => 'https://gallery.test'])->postJson('/api/version')->assertMethodNotAllowed()->assertJsonPath('type', problemType('MethodNotAllowed'));
});

test('storage:link links public/media to the media folder', function (): void {
    expect(config('filesystems.links'))->toBe([public_path('media') => config()->string('gallery.media_path')]);
});

test('a missing file is a 404, never the page Cloudflare would cache as the file', function (string $path): void {
    get($path)->assertNotFound()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertContent('Not found.');
})->with([
    'a build asset' => '/assets/index-Missing.js',
    'a build asset in a folder' => '/assets/fonts/missing.woff2',
    'the API reference\'s files' => '/api-docs/swagger-ui.css',
    'a full-size file' => '/media/full/missing.jpg',
    'a thumbnail' => '/media/thumbs/missing@2x.webp',
    'a thumbnail named like a page' => '/media/thumbs/1',
    'a top-level icon' => '/favicon.ico',
    'a top-level script' => '/registerSW.js',
    'a manifest' => '/manifest.webmanifest',
    'any case' => '/APPLE-TOUCH-ICON.PNG',
    'a top-level file with a trailing slash' => '/robots.txt/',
]);

test('a page of the gallery\'s own says what it holds in a link preview', function (string $path, string $title): void {
    get($path)->assertOk()->assertSeeHtml("<title>{$title}</title>");
})->with([
    'the privacy page' => ['/privacy', 'Gallery — Privacy'],
    'the accessibility statement' => ['/accessibility', 'Gallery — Accessibility'],
]);

test('a page whose path holds a dot is still the page', function (string $path): void {
    get($path)->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8')->assertSeeHtml('<div id="app"></div>');
})->with([
    'a tag named like a version' => '/media/1/40/with-tags/v1.5',
    'a tag named like a file' => '/media/1/40/with-tags/cover.png',
    'an excluded tag named like a file' => '/media/1/40/with-tags/cat%2C-icon.svg',
    'the shell\'s own file name' => '/index.html',
    'an unknown page' => '/no-such-page.html',
    'the media page, not the media folder' => '/media',
]);

test('a trailing slash answers as the path without it', function (): void {
    $withSlash = get('/tags/')->assertOk()->baseResponse->getContent();
    $without = get('/tags')->assertOk()->baseResponse->getContent();

    expect($withSlash)->toBe($without)->toContain('<title>Gallery — Tags</title>');
});

test('.htaccess 404s the same files the shell does', function (): void {
    $htaccess = (string) file_get_contents(public_path('.htaccess'));
    preg_match('#RewriteRule \^\(\?:([a-z/|-]+)\)/ - \[R=404#', $htaccess, $folders);
    preg_match('#RewriteRule \^\[\^/\]\+\\\.\(\?:([a-z0-9|]+)\)\$ - \[NC,R=404#', $htaccess, $extensions);

    expect(explode('|', $folders[1] ?? ''))->toBe(SpaController::STATIC_FOLDERS)
        ->and(explode('|', $extensions[1] ?? ''))->toBe(SpaController::STATIC_EXTENSIONS);
});

test('the build leaves the placeholder the shell replaces with each answer\'s nonce', function (): void {
    expect((string) file_get_contents(base_path('../frontend/vite.config.ts')))
        ->toContain("cspNonce: '" . SpaController::NONCE_PLACEHOLDER . "'");
});
