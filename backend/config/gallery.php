<?php

declare(strict_types=1);

use Illuminate\Support\Env;

/*
|--------------------------------------------------------------------------
| Gallery settings
|--------------------------------------------------------------------------
|
| Every variable in the Slim backend's .env.example, read the way its
| Gallery\Core\Configuration reads it: an empty value counts as unset. Code
| reads these with config('gallery.…'), never env(), so they still resolve
| after `php artisan config:cache`.
|
| Values are read raw instead of through env(), which turns "true", "null",
| "(empty)" and the like into other types. A password is whatever was typed.
|
*/

$raw = static fn(string $name): string => (string) Env::getRepository()->get($name);
$orDefault = static fn(string $value, string $default): string => $value !== '' ? $value : $default;

$origins = $raw('GALLERY_ALLOWED_ORIGINS');

return [

    // The application release (SemVer, as frontend/package.json, which CI tags
    // releases from) and the HTTP API contract version, which moves only when
    // the API surface changes and is set in the OpenAPI document's metadata.
    // Both are served by GET /api/version.
    'version' => '7.0.0',
    'api_version' => App\OpenApi\OpenApiSpec::API_VERSION,

    // Whether anyone may create an account: on unless set to "false" (or "0",
    // "off", "no"). Admins come from `php artisan gallery:user`, never sign-up.
    'allow_signup' => !in_array(strtolower($raw('GALLERY_ALLOW_SIGNUP')), ['false', '0', 'off', 'no'], true),

    // Cloudflare Turnstile, the check that sign-up and login aren't a bot's.
    // The site key goes to the browser (GET /api/auth/config), the secret only
    // to Cloudflare. Without a secret nothing is checked: local development
    // and the tests run without one.
    'turnstile' => [
        'site_key' => $raw('CLOUDFLARE_TURNSTILE_SITEKEY'),
        'secret' => $raw('CLOUDFLARE_TURNSTILE_SECRET'),
    ],

    // Public base URL, without a trailing slash. Builds the absolute image URLs
    // Danbooru's IQDB lookup fetches.
    'url' => rtrim($raw('GALLERY_URL'), '/'),

    // Origins allowed by CORS and by the CSRF origin check, comma-separated.
    // Unset, only local development origins are allowed.
    'allowed_origins' => $origins !== ''
        ? array_map(trim(...), explode(',', $origins))
        : ['http://localhost', 'http://localhost:5173', 'https://localhost'],

    // FontAwesome kit id, injected into the SPA shell's <head>.
    'fontawesome_kit_id' => $raw('FONTAWESOME_KIT_ID'),

    // Danbooru API credentials for tag imports; both are needed. The account's
    // numeric user ID goes in the User-Agent ("…; user #123"), which Danbooru
    // asks of API clients: its firewall blocks requests without one.
    'danbooru' => [
        'login' => $raw('DANBOORU_LOGIN'),
        'api_key' => $raw('DANBOORU_API_KEY'),
        'user_id' => $raw('DANBOORU_USER_ID'),
    ],

    // Duplicate-scan tuning, as written: the largest Hamming distance reported
    // (0-16, default 6) and the largest worst-region difference (0-255 or "off",
    // default 36). Unset or invalid values fall back to the defaults where the
    // scanner reads them, as Configuration::getDuplicateMax*() do today.
    'duplicates' => [
        'max_distance' => $raw('DUPLICATE_MAX_DISTANCE'),
        'max_region_diff' => $raw('DUPLICATE_MAX_REGION_DIFF'),
    ],

    // Where media files (full/, thumbs/ and the ingest folder) and duplicate-scan
    // reports live. On the droplet both sit beside the app in its folder, outside
    // public/ (docs/laravel-migration.md, Deployment).
    'media_path' => $orDefault($raw('GALLERY_MEDIA_PATH'), storage_path('app/media')),
    'dupes_path' => $orDefault($raw('GALLERY_DUPES_PATH'), storage_path('app/dupes')),

    // The SPA's built index.html (frontend/dist/), which the SPA shell serves.
    // It sits outside public/ so Apache never serves it without the shell's
    // link-preview tags; the deploy copies it here.
    'spa_index' => resource_path('spa/index.html'),

];
