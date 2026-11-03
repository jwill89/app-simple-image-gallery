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

it('reads every variable in the Slim backend\'s .env.example', function (): void {
    $config = galleryConfig([
        'GALLERY_ADMIN_PASSWORD' => 'secret',
        'GALLERY_URL' => 'https://gallery.test',
        'GALLERY_ALLOWED_ORIGINS' => 'https://gallery.test',
        'DANBOORU_LOGIN' => 'someone',
        'DANBOORU_API_KEY' => 'key',
        'FONTAWESOME_KIT_ID' => 'kit',
        'DUPLICATE_MAX_DISTANCE' => '8',
        'DUPLICATE_MAX_REGION_DIFF' => 'off',
        'GALLERY_MEDIA_PATH' => '/data/media',
        'GALLERY_DUPES_PATH' => '/data/dupes',
    ]);

    expect($config)->toBe([
        'version' => '4.0.0',
        'api_version' => '4.0.0',
        'admin_password' => 'secret',
        'url' => 'https://gallery.test',
        'allowed_origins' => ['https://gallery.test'],
        'fontawesome_kit_id' => 'kit',
        'danbooru' => ['login' => 'someone', 'api_key' => 'key'],
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

it('keeps a password that env() would turn into another type', function (string $password): void {
    expect(galleryConfig(['GALLERY_ADMIN_PASSWORD' => $password])['admin_password'])->toBe($password);
})->with(['null', 'true', 'false', '(empty)']);

it('treats an empty admin password as unset', function (): void {
    expect(galleryConfig(['GALLERY_ADMIN_PASSWORD' => ''])['admin_password'])->toBe('');
});

it('keeps media and reports under storage/app when no paths are set', function (): void {
    $config = galleryConfig(['GALLERY_MEDIA_PATH' => '', 'GALLERY_DUPES_PATH' => '']);

    expect($config['media_path'])->toBe(storage_path('app/media'))
        ->and($config['dupes_path'])->toBe(storage_path('app/dupes'));
});
