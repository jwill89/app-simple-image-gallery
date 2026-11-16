<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * Evaluates config/$file as if $vars were in .env, with the app at $basePath
 * when one is given, then puts the environment and the base path back.
 *
 * @param array<string, string> $vars
 * @return array<string, mixed>
 */
function configWithEnvironment(string $file, array $vars, ?string $basePath = null): array
{
    $path = config_path($file);
    $originalBase = app()->basePath();
    $saved = [];
    foreach ($vars as $name => $value) {
        $saved[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null];
        $_SERVER[$name] = $_ENV[$name] = $value;
    }

    try {
        if ($basePath !== null) {
            app()->setBasePath($basePath);
        }
        $config = require $path;
    } finally {
        app()->setBasePath($originalBase);
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
        throw new UnexpectedValueException("config/{$file} did not return an array.");
    }

    /** @var array<string, mixed> $config */
    return $config;
}

/**
 * Evaluates config/gallery.php as if $vars were in .env.
 *
 * @param array<string, string> $vars
 * @return array<string, mixed>
 */
function galleryConfig(array $vars, ?string $basePath = null): array
{
    return configWithEnvironment('gallery.php', $vars, $basePath);
}

/** The release frontend/package.json names. */
function frontendVersion(): string
{
    $package = json_decode((string) file_get_contents(base_path('../frontend/package.json')), true);

    return is_array($package) && is_string($package['version'] ?? null) ? $package['version'] : throw new UnexpectedValueException('frontend/package.json has no version.');
}

/** A new, empty folder to stand in for the app's own, in a folder of its own. */
function scratchBase(): string
{
    $base = sys_get_temp_dir() . '/gallery-config-' . bin2hex(random_bytes(4)) . '/app';
    File::ensureDirectoryExists($base);

    return $base;
}

afterEach(function (): void {
    $folders = glob(sys_get_temp_dir() . '/gallery-config-*');
    foreach ($folders === false ? [] : $folders as $folder) {
        File::deleteDirectory($folder);
    }
});

/** Every gallery variable, with a value of its own. */
const GALLERY_VARIABLES = [
    'GALLERY_ALLOW_SIGNUP' => 'false',
    'GALLERY_CSP_REPORT_ONLY' => 'true',
    'GALLERY_TURNSTILE_SITEKEY' => 'site-key',
    'GALLERY_TURNSTILE_SECRET' => 'secret-key',
    'GALLERY_URL' => 'https://gallery.test',
    'GALLERY_ALLOWED_ORIGINS' => 'https://gallery.test',
    'GALLERY_DANBOORU_LOGIN' => 'someone',
    'GALLERY_DANBOORU_API_KEY' => 'key',
    'GALLERY_DANBOORU_USER_ID' => '123',
    'GALLERY_DUPLICATE_MAX_DISTANCE' => '8',
    'GALLERY_DUPLICATE_MAX_REGION_DIFF' => 'off',
    'GALLERY_MEDIA_PATH' => '/data/media',
    'GALLERY_DUPES_PATH' => '/data/dupes',
];

it('reads every gallery variable in .env.example', function (): void {
    expect(galleryConfig(GALLERY_VARIABLES))->toBe([
        'version' => frontendVersion(),
        'api_version' => '8.0.0',
        'allow_signup' => false,
        'turnstile' => ['site_key' => 'site-key', 'secret' => 'secret-key'],
        'url' => 'https://gallery.test',
        'allowed_origins' => ['https://gallery.test'],
        'danbooru' => ['login' => 'someone', 'api_key' => 'key', 'user_id' => '123'],
        'duplicates' => ['max_distance' => '8', 'max_region_diff' => 'off'],
        'media_path' => '/data/media',
        'dupes_path' => '/data/dupes',
        'csp_report_only' => true,
        'spa_index' => resource_path('spa/index.html'),
    ]);
    expect(configWithEnvironment('app.php', ['GALLERY_SCHEDULE_TIMEZONE' => 'America/Chicago'])['schedule_timezone'])->toBe('America/Chicago');
});

it('names in .env.example exactly the gallery variables the config reads', function (): void {
    preg_match_all('/^(GALLERY_\w+)=/m', (string) file_get_contents(base_path('.env.example')), $names);
    $expected = [...array_keys(GALLERY_VARIABLES), 'GALLERY_SCHEDULE_TIMEZONE'];
    sort($expected);
    $listed = $names[1];
    sort($listed);

    expect($listed)->toBe($expected);
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

it('enforces the Content-Security-Policy unless it is switched to report-only', function (string $value, bool $reportOnly): void {
    expect(galleryConfig(['GALLERY_CSP_REPORT_ONLY' => $value])['csp_report_only'])->toBe($reportOnly);
})->with([
    'unset' => ['', false],
    'false' => ['false', false],
    '0' => ['0', false],
    'off' => ['off', false],
    'no' => ['no', false],
    'true' => ['true', true],
    'TRUE, padded' => [' TRUE ', true],
    '1' => ['1', true],
    'on' => ['on', true],
    'yes' => ['yes', true],
]);

it('refuses a report-only switch that is neither true nor false, naming it', function (): void {
    expect(static fn() => galleryConfig(['GALLERY_CSP_REPORT_ONLY' => 'ture']))
        ->toThrow(InvalidArgumentException::class, 'GALLERY_CSP_REPORT_ONLY must be true or false, not "ture".');
});

it('keeps media and reports under storage/app when no paths are set', function (): void {
    $config = galleryConfig(['GALLERY_MEDIA_PATH' => '', 'GALLERY_DUPES_PATH' => '']);

    expect($config['media_path'])->toBe(storage_path('app/media'))
        ->and($config['dupes_path'])->toBe(storage_path('app/dupes'));
});

it('reads the version from frontend/package.json in a checkout', function (): void {
    expect(is_file(base_path('package.json')))->toBeFalse()
        ->and(config('gallery.version'))->toBe(frontendVersion())
        ->and(galleryConfig([])['version'])->toBe(frontendVersion());
});

it('reads the version from the package.json beside the app, where a release ships it', function (): void {
    $base = scratchBase();
    file_put_contents("{$base}/package.json", json_encode(['name' => 'frontend', 'version' => '9.8.7']));

    expect(galleryConfig([], $base)['version'])->toBe('9.8.7');
});

it('refuses a package.json without a version', function (): void {
    $base = scratchBase();
    file_put_contents("{$base}/package.json", json_encode(['name' => 'frontend']));

    expect(static fn() => galleryConfig([], $base))->toThrow(UnexpectedValueException::class, 'package.json has no version.');
});

it('refuses to start without a package.json to read the version from', function (): void {
    expect(static fn() => galleryConfig([], scratchBase()))->toThrow(UnexpectedValueException::class, 'No package.json to read the version from');
});
