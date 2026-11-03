<?php

declare(strict_types=1);

/*
 * Where the Laravel app answers differently from the recordings on purpose,
 * decided before Phase 3 (docs/laravel-migration.md, "Contract changes").
 * Each entry replaces the recorded response of the listed steps (0-based) with
 * the expected one, in the recordings' format.
 *
 * Three smaller differences are rules in Tests\Support\Contract\Replay instead,
 * since they apply to whole kinds of response: Access-Control-Allow-Headers,
 * -Methods and -Max-Age are sent only on preflights (where browsers read
 * them), a response the Slim app sent without Cache-Control may carry
 * Symfony's default "no-cache, private", and the SPA shell's pages carry
 * X-Frame-Options and X-Content-Type-Options, which the Slim app set only on
 * the API.
 */

$security = ['x-content-type-options' => 'nosniff', 'x-frame-options' => 'SAMEORIGIN'];
$cors = ['access-control-allow-credentials' => 'true', 'access-control-allow-origin' => 'https://gallery.test'];
$json = ['content-type' => 'application/json'];

$notFound = [
    'status' => 404,
    'headers' => $json + $security,
    'json' => ['error' => 'NotFound', 'message' => 'The requested endpoint does not exist.'],
];
$methodNotAllowed = static fn(string $method, string $allow): array => [
    'status' => 405,
    'headers' => ['allow' => $allow] + $json + $cors + $security,
    'json' => ['error' => 'MethodNotAllowed', 'message' => "This endpoint does not support {$method} requests."],
];
$preflight = [
    'status' => 204,
    'headers' => $cors + $security + [
        'access-control-allow-headers' => 'x-requested-with, content-type, accept, origin, authorization',
        'access-control-allow-methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
        'access-control-max-age' => '86400',
    ],
    'text' => '',
];

return [
    'errors/unmatched-path' => [
        'reason' => 'An unmatched path is 404 NotFound as { error, message }, not Slim\'s 405 page from its OPTIONS catch-all.',
        'steps' => [
            0 => $notFound,
            1 => $notFound,
            2 => ['headers' => $notFound['headers'] + $cors] + $notFound,
            3 => $notFound,
        ],
    ],
    'errors/method-not-allowed' => [
        'reason' => 'A wrong method is 405 MethodNotAllowed as { error, message }, not Slim\'s HTML or { message } page.',
        'steps' => [
            0 => $methodNotAllowed('PUT', 'GET, HEAD, DELETE'),
            1 => $methodNotAllowed('PUT', 'GET, HEAD, DELETE'),
            2 => $methodNotAllowed('PATCH', 'GET, HEAD'),
        ],
    ],
    'cors/preflight' => [
        'reason' => 'Laravel\'s CORS handling answers a preflight 204, not 200.',
        'steps' => [
            0 => $preflight,
            1 => $preflight,
            2 => ['status' => 204, 'headers' => $security, 'text' => ''],
        ],
    ],
    'rate-limit/exceeded' => [
        'reason' => 'Laravel\'s CORS handling answers a preflight 204, not 200.',
        'steps' => [3 => $preflight],
    ],
    'tags/update-name-taken' => [
        'reason' => 'Renaming a tag onto an existing name is 400 TagAlreadyExists, as creating one is, not a 500 from the UNIQUE constraint.',
        'steps' => [
            0 => [
                'status' => 400,
                'headers' => $json + $cors + $security + ['x-ratelimit-remaining' => '119'],
                'json' => ['error' => 'TagAlreadyExists', 'message' => 'A tag named "cat" already exists.'],
            ],
        ],
    ],
    'duplicates/dismiss-unknown-media' => [
        'reason' => 'Dismissing a pair with an unknown media id is 404 MediaNotFound, not a 500 from the foreign key.',
        'steps' => [
            0 => [
                'status' => 404,
                'headers' => $json + $cors + $security + ['x-ratelimit-remaining' => '119'],
                'json' => ['error' => 'MediaNotFound', 'message' => 'One or both media items could not be found.'],
            ],
        ],
    ],
];
