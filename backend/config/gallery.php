<?php

declare(strict_types=1);

use Illuminate\Support\Env;

/*
|--------------------------------------------------------------------------
| Gallery settings
|--------------------------------------------------------------------------
|
| Every gallery variable in .env, where an empty value counts as unset. Code
| reads these with config('gallery.…'), never env(), so they still resolve
| after `php artisan config:cache`.
|
| Values are read raw instead of through env(), which turns "true", "null",
| "(empty)" and the like into other types. A password is whatever was typed.
|
*/

$raw = static fn(string $name): string => (string) Env::getRepository()->get($name);
$orDefault = static fn(string $value, string $default): string => $value !== '' ? $value : $default;

// A yes-or-no setting: unset or empty is $default, and anything but true or false
// (or 1/0, on/off, yes/no) is refused with an error that names the variable.
$flag = static function (string $name, bool $default) use ($raw): bool {
    $given = $raw($name);
    $value = strtolower(trim($given));

    return match (true) {
        $value === '' => $default,
        in_array($value, ['true', '1', 'on', 'yes'], true) => true,
        in_array($value, ['false', '0', 'off', 'no'], true) => false,
        default => throw new InvalidArgumentException("{$name} must be true or false, not \"{$given}\"."),
    };
};

// The release, as frontend/package.json gives it, the one place it is written: a
// release's copy beside the app (the deploy ships it there), else the front end's
// own (a checkout: development, CI, the end-to-end run).
$version = static function (): string {
    foreach ([base_path('package.json'), base_path('../frontend/package.json')] as $file) {
        if (!is_file($file)) {
            continue;
        }
        $package = json_decode((string) file_get_contents($file), true);
        $version = is_array($package) ? ($package['version'] ?? null) : null;
        if (!is_string($version) || $version === '') {
            throw new UnexpectedValueException("{$file} has no version.");
        }

        return $version;
    }

    throw new UnexpectedValueException('No package.json to read the version from: neither ' . base_path('package.json') . ' nor ' . base_path('../frontend/package.json') . ' exists.');
};

$origins = $raw('GALLERY_ALLOWED_ORIGINS');

return [

    // The application release (SemVer, from package.json, which CI tags releases
    // from) and the HTTP API contract version, which moves only when the API
    // surface changes and is set in the OpenAPI document's metadata. Both are
    // served by GET /api/version.
    'version' => $version(),
    'api_version' => App\OpenApi\OpenApiSpec::API_VERSION,

    // Whether anyone may create an account: on unless set to "false" (or "0",
    // "off", "no"). Admins come from `php artisan gallery:user`, never sign-up.
    'allow_signup' => !in_array(strtolower($raw('GALLERY_ALLOW_SIGNUP')), ['false', '0', 'off', 'no'], true),

    // Cloudflare Turnstile, the check that sign-up and login aren't a bot's.
    // The site key goes to the browser (GET /api/auth/config), the secret only
    // to Cloudflare. Without a secret nothing is checked: local development
    // and the tests run without one.
    'turnstile' => [
        'site_key' => $raw('GALLERY_TURNSTILE_SITEKEY'),
        'secret' => $raw('GALLERY_TURNSTILE_SECRET'),
    ],

    // Public base URL, without a trailing slash. Builds the absolute image URLs
    // Danbooru's IQDB lookup fetches.
    'url' => rtrim($raw('GALLERY_URL'), '/'),

    // Origins allowed by CORS and by the CSRF origin check, comma-separated.
    // Unset, only local development origins are allowed.
    'allowed_origins' => $origins !== ''
        ? array_map(trim(...), explode(',', $origins))
        : ['http://localhost', 'http://localhost:5173', 'https://localhost'],

    // Danbooru API credentials for tag imports; both are needed. The account's
    // numeric user ID goes in the User-Agent ("…; user #123"), which Danbooru
    // asks of API clients: its firewall blocks requests without one.
    'danbooru' => [
        'login' => $raw('GALLERY_DANBOORU_LOGIN'),
        'api_key' => $raw('GALLERY_DANBOORU_API_KEY'),
        'user_id' => $raw('GALLERY_DANBOORU_USER_ID'),
    ],

    // Duplicate-scan tuning, as written: the largest Hamming distance reported
    // (0-16, default 6) and the largest worst-region difference (0-255 or "off",
    // default 36). Unset or invalid values fall back to the defaults where the
    // scanner reads them (ScanSettings).
    'duplicates' => [
        'max_distance' => $raw('GALLERY_DUPLICATE_MAX_DISTANCE'),
        'max_region_diff' => $raw('GALLERY_DUPLICATE_MAX_REGION_DIFF'),
    ],

    // Where media files (full/, thumbs/ and the ingest folder) and duplicate-scan
    // reports live. On the droplet both sit beside the app in its folder, outside
    // public/, so only the media link in public/ exposes any of it.
    'media_path' => $orDefault($raw('GALLERY_MEDIA_PATH'), storage_path('app/media')),
    'dupes_path' => $orDefault($raw('GALLERY_DUPES_PATH'), storage_path('app/dupes')),

    // Whether the Content-Security-Policy only reports what it would refuse
    // (Content-Security-Policy-Report-Only) instead of refusing it: off unless
    // set. For when the enforced policy blocks something in production.
    'csp_report_only' => $flag('GALLERY_CSP_REPORT_ONLY', false),

    // The SPA's built index.html (frontend/dist/), which the SPA shell serves.
    // It sits outside public/ so Apache never serves it without the shell's
    // link-preview tags; the deploy copies it here.
    'spa_index' => resource_path('spa/index.html'),

];
