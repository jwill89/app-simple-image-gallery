<?php

declare(strict_types=1);

/**
 * Evaluates config/gallery.php as if $vars were in .env, then puts the
 * environment back.
 *
 * @param array<string, string> $vars
 * @return array<string, mixed>
 */
function galleryConfig(array $vars): array
{
    $saved = [];
    foreach ($vars as $name => $value) {
        $saved[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null];
        $_SERVER[$name] = $_ENV[$name] = $value;
    }

    try {
        $config = require config_path('gallery.php');
    } finally {
        foreach ($saved as $name => [$server, $env]) {
            if ($server === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }
            if ($env === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $env;
            }
        }
    }

    if (!is_array($config)) {
        throw new UnexpectedValueException('config/gallery.php did not return an array.');
    }

    /** @var array<string, mixed> $config */
    return $config;
}

it('reads every gallery variable in .env.example', function (): void {
    $config = galleryConfig([
        'GALLERY_ALLOW_SIGNUP' => 'false',
        'CLOUDFLARE_TURNSTILE_SITEKEY' => 'site-key',
        'CLOUDFLARE_TURNSTILE_SECRET' => 'secret-key',
        'GALLERY_URL' => 'https://gallery.test',
        'GALLERY_ALLOWED_ORIGINS' => 'https://gallery.test',
        'DANBOORU_LOGIN' => 'someone',
        'DANBOORU_API_KEY' => 'key',
        'DANBOORU_USER_ID' => '123',
        'DUPLICATE_MAX_DISTANCE' => '8',
        'DUPLICATE_MAX_REGION_DIFF' => 'off',
        'GALLERY_MEDIA_PATH' => '/data/media',
        'GALLERY_DUPES_PATH' => '/data/dupes',
    ]);

    expect($config)->toBe([
        'version' => '7.0.1',
        'api_version' => '7.0.0',
        'allow_signup' => false,
        'turnstile' => ['site_key' => 'site-key', 'secret' => 'secret-key'],
        'url' => 'https://gallery.test',
        'allowed_origins' => ['https://gallery.test'],
        'danbooru' => ['login' => 'someone', 'api_key' => 'key', 'user_id' => '123'],
        'duplicates' => ['max_distance' => '8', 'max_region_diff' => 'off'],
        'media_path' => '/data/media',
        'dupes_path' => '/data/dupes',
        'spa_index' => resource_path('spa/index.html'),
    ]);
});

it('splits and trims the allowed origins', function (): void {
    $config = galleryConfig(['GALLERY_ALLOWED_ORIGINS' => ' https://gallery.test , http://localhost:5173 ']);

    expect($config['allowed_origins'])->toBe(['https://gallery.test', 'http://localhost:5173']);
});

it('allows only local origins when none are configured', function (): void {
    $config = galleryConfig(['GALLERY_ALLOWED_ORIGINS' => '']);

    expect($config['allowed_origins'])->toBe(['http://localhost', 'http://localhost:5173', 'https://localhost']);
});

it('drops a trailing slash from the public URL', function (): void {
    expect(galleryConfig(['GALLERY_URL' => 'https://gallery.test/'])['url'])->toBe('https://gallery.test');
});

it('takes sign-up as open unless it is switched off', function (string $value, bool $open): void {
    expect(galleryConfig(['GALLERY_ALLOW_SIGNUP' => $value])['allow_signup'])->toBe($open);
})->with([
    'unset' => ['', true],
    'true' => ['true', true],
    'false' => ['false', false],
    'FALSE' => ['FALSE', false],
    '0' => ['0', false],
    'off' => ['off', false],
    'no' => ['no', false],
]);

it('keeps media and reports under storage/app when no paths are set', function (): void {
    $config = galleryConfig(['GALLERY_MEDIA_PATH' => '', 'GALLERY_DUPES_PATH' => '']);

    expect($config['media_path'])->toBe(storage_path('app/media'))
        ->and($config['dupes_path'])->toBe(storage_path('app/dupes'));
});
