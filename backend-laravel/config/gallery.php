<?php

declare(strict_types=1);

use Illuminate\Support\Env;

/*
|--------------------------------------------------------------------------
| Gallery settings
|--------------------------------------------------------------------------
|
| Every variable in backend/.env.example, read the way the Slim backend's
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

    // Admin password for the protected endpoints. Login is refused while it is
    // empty, so no default password can ever grant access.
    'admin_password' => $raw('GALLERY_ADMIN_PASSWORD'),

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

    // Danbooru API credentials for tag imports; both are needed.
    'danbooru' => [
        'login' => $raw('DANBOORU_LOGIN'),
        'api_key' => $raw('DANBOORU_API_KEY'),
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
    // reports live. On the droplet both sit in /var/www/gallery-data/, shared
    // with the Slim app during the switch (docs/laravel-migration.md).
    'media_path' => $orDefault($raw('GALLERY_MEDIA_PATH'), storage_path('app/media')),
    'dupes_path' => $orDefault($raw('GALLERY_DUPES_PATH'), storage_path('app/dupes')),

];
