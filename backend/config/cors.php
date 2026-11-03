<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS)
|--------------------------------------------------------------------------
|
| The Slim app's CORS rules for /api. The allowed origins are
| config('gallery.allowed_origins') (GALLERY_ALLOWED_ORIGINS), which
| AppServiceProvider copies in, since this file is read before gallery.php.
| The same list backs the origin check (App\Http\Middleware\VerifyOrigin).
|
*/

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['X-Requested-With', 'Content-Type', 'Accept', 'Origin', 'Authorization'],

    'exposed_headers' => [],

    'max_age' => 86400,

    'supports_credentials' => true,

];
