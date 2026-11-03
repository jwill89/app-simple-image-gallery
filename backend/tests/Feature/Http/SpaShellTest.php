<?php

declare(strict_types=1);

use Tests\Support\ContractFixture;

use function Pest\Laravel\get;
use function Pest\Laravel\withHeaders;

// The SPA shell, beyond what its recordings show (contract recordings spa/*):
// it sets no cookies, it leaves /api to the API, and storage:link points
// public/media at the media folder.

beforeEach(function (): void {
    config(['gallery.spa_index' => ContractFixture::path('spa/index.html')]);
});

test('a page sets no session or cookie', function (): void {
    get('/favorites')->assertOk()->assertHeaderMissing('Set-Cookie');
});

test('an /api path no route answers stays a JSON error, whatever the method', function (): void {
    get('/api/no-such-route')->assertNotFound()->assertJsonPath('error', 'NotFound');
    config(['gallery.allowed_origins' => ['https://gallery.test']]);
    withHeaders(['Origin' => 'https://gallery.test'])->postJson('/api/version')->assertMethodNotAllowed()->assertJsonPath('error', 'MethodNotAllowed');
});

test('storage:link links public/media to the media folder', function (): void {
    expect(config('filesystems.links'))->toBe([public_path('media') => config()->string('gallery.media_path')]);
});
