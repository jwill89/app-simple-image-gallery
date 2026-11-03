<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Passkeys (laravel/passkeys)
|--------------------------------------------------------------------------
|
| Sign-in by passkey, through App\Http\Controllers\Api\PasskeyController; the
| package's own routes are off (AppServiceProvider). A passkey belongs to one
| site, the relying party: APP_URL's host, so one made on the gallery works
| only there, and one made in local development only on localhost. The page
| asking must be an origin the gallery allows (GALLERY_ALLOWED_ORIGINS). What
| isn't here is the package's default (vendor/laravel/passkeys/config).
|
*/

$host = parse_url((string) config('app.url'), PHP_URL_HOST);

return [
    'relying_party_id' => is_string($host) && $host !== '' ? $host : 'localhost',

    'allowed_origins' => config('gallery.allowed_origins'),

    // How long the browser has to answer, in milliseconds.
    'timeout' => 60000,
];
