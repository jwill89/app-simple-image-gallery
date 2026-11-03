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

$divergences = [
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

/*
 * API 5 (2026-11) changed these cases on purpose, too much for step-by-step
 * divergences, so they are set aside: the tests named say what API 5 answers.
 * A field API 5 only added (a validation failure's `errors`, a media page's
 * `next_cursor`) is a rule in Replay instead, as are the ones above.
 */
$superseded = static fn(string $reason): array => ['superseded' => $reason];
$missingRecord = $superseded('A path id is digits and names a record that exists, or the request is a 404 with the resource\'s code (MediaNotFound, TagNotFound, CategoryNotFound, MappingNotFound, ImplicationNotFound), where API 4 answered 400 for a malformed id, 200 or 204 for a missing one, and used several codes. tests/Feature/Http/Api5Test.php.');
$danbooru = $superseded('The Danbooru import no longer returns `all_tags` (every tag in the gallery). tests/Feature/Http/Api5Test.php.');
$upload = $superseded('An upload queues its thumbnails and Danbooru lookup: a file says tags_status "queued" (or why not, as tags_error), GET /media/tag-imports reports the outcome, and total_tags_applied is gone. tests/Feature/Http/UploadQueueTest.php.');
$partial = $superseded('A PUT or PATCH changes only the fields it sends; API 4 reset the rest to their defaults. tests/Feature/Http/Api5Test.php.');
$accounts = $superseded('API 6 has accounts: login takes a username and password (MissingCredentials 400, InvalidCredentials 401) and answers { token, user }; the shared GALLERY_ADMIN_PASSWORD, and AdminNotConfigured with it, are gone. tests/Feature/Http/AccountsTest.php.');

return [
    ...$divergences,
    'auth/login' => $accounts,
    'auth/login-missing-password' => $accounts,
    'auth/login-not-configured' => $accounts,
    'auth/login-throttled' => $accounts,
    'auth/login-wrong-password' => $accounts,
    'danbooru/category-mapping-delete' => $missingRecord,
    'danbooru/tag-mapping-delete' => $missingRecord,
    'danbooru/tag-mapping-update-absent' => $missingRecord,
    'danbooru/tag-mapping-update-invalid' => $missingRecord,
    'media/bulk-delete' => $missingRecord,
    'media/danbooru-media-not-found' => $missingRecord,
    'media/delete' => $missingRecord,
    'media/delete-invalid-id' => $missingRecord,
    'media/delete-not-found' => $missingRecord,
    'media/show-invalid-id' => $missingRecord,
    'media/show-not-found' => $missingRecord,
    'media/tags-add-invalid' => $missingRecord,
    'media/tags-add-media-not-found' => $missingRecord,
    'media/tags-not-found' => $missingRecord,
    'media/tags-remove-invalid-id' => $missingRecord,
    'media/tags-remove-not-found' => $missingRecord,
    'tag-categories/delete-not-found' => $missingRecord,
    'tag-categories/update-invalid' => $missingRecord,
    'tag-implications/create-invalid' => $missingRecord,
    'tag-implications/delete-absent' => $missingRecord,
    'tags/delete' => $missingRecord,
    'tags/delete-migrate' => $missingRecord,
    'tags/delete-migrate-invalid' => $missingRecord,
    'tags/delete-migrate-to-zero' => $missingRecord,
    'tags/delete-not-found' => $missingRecord,
    'tags/migrate-invalid' => $missingRecord,
    'tags/show-not-found' => $missingRecord,
    'tags/update-not-found' => $missingRecord,
    'media/danbooru-iqdb' => $danbooru,
    'media/danbooru-md5' => $danbooru,
    'media/danbooru-post-id' => $danbooru,
    'media/upload-fetch-tags' => $upload,
    'media/upload-fetch-tags-not-configured' => $upload,
    'tag-categories/update-partial' => $partial,
    'tags/update-default-category' => $partial,
    'tags/display' => $superseded('GET /tags/display is a page of tags (items, total, current_page, total_pages) with search, sort and ids, not every tag. tests/Feature/Http/TagSearchTest.php.'),
];
