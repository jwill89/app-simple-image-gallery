<?php

declare(strict_types=1);

/*
 * Where the app answers differently from the recordings on purpose, beyond
 * what translating them into API 8's terms covers (Tests\Support\Contract\Api8:
 * camelCase, the listings in GET /media, PATCH for PUT, problem details and
 * their statuses, RFC 3339 times, X-Total-Count). Each entry replaces the
 * expected response of the listed steps (0-based), written as API 8 answers
 * the translated request, in the recordings' format.
 *
 * Three smaller differences are rules in Tests\Support\Contract\Replay instead,
 * since they apply to whole kinds of response: Access-Control-Allow-Headers,
 * -Methods and -Max-Age are sent only on preflights (where browsers read
 * them), a response recorded without Cache-Control may carry Symfony's default
 * "no-cache, private", and the SPA shell's pages carry X-Frame-Options and
 * X-Content-Type-Options, which the recordings show only on the API.
 */

$security = ['x-content-type-options' => 'nosniff', 'x-frame-options' => 'SAMEORIGIN'];
$cors = ['access-control-allow-credentials' => 'true', 'access-control-allow-origin' => 'https://gallery.test'];
$problemType = ['content-type' => 'application/problem+json'];
$titles = [400 => 'Bad Request', 404 => 'Not Found', 405 => 'Method Not Allowed', 409 => 'Conflict', 422 => 'Unprocessable Content', 500 => 'Internal Server Error'];
/** A problem details response, as API 8 sends every error. */
$problem = static fn(int $status, string $code, string $detail, array $headers): array => [
    'status' => $status,
    'headers' => $problemType + $headers + $security,
    'json' => ['type' => problemType($code), 'title' => $titles[$status], 'status' => $status, 'detail' => $detail],
];
$read = ['x-ratelimit-remaining' => '119'];
$write = $cors + $read;

$notFound = $problem(404, 'NotFound', 'The requested endpoint does not exist.', []);
$methodNotAllowed = static fn(string $method, string $allow): array => $problem(405, 'MethodNotAllowed', "This endpoint does not support {$method} requests.", ['allow' => $allow] + $cors);
$badOffset = [0 => $problem(422, 'InvalidInput', 'offset is a whole number, 0 or more.', $read)];
$badLimit = [0 => $problem(422, 'InvalidInput', 'limit is a whole number from 1 to 100.', $read)];
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
        'reason' => 'An unmatched path is 404 NotFound as problem details, not the recorded 405 page from an OPTIONS catch-all.',
        'steps' => [
            0 => $notFound,
            1 => $notFound,
            2 => ['headers' => $notFound['headers'] + $cors] + $notFound,
            3 => $notFound,
        ],
    ],
    'errors/method-not-allowed' => [
        'reason' => 'A wrong method is 405 MethodNotAllowed as problem details, not the recorded HTML or { message } page.',
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
        'reason' => 'Renaming a tag onto an existing name is 409 TagAlreadyExists, as creating one is, not a 500 from the UNIQUE constraint.',
        'steps' => [0 => $problem(409, 'TagAlreadyExists', 'A tag named "cat" already exists.', $write)],
    ],
    'duplicates/dismiss-unknown-media' => [
        'reason' => 'Dismissing a pair with an unknown media id is 422 MediaNotFound (a reference to nothing), not a 500 from the foreign key.',
        'steps' => [0 => $problem(422, 'MediaNotFound', 'One or both media items could not be found.', $write)],
    ],
    'tags/create-invalid-name' => [
        'reason' => 'A body sent as JSON that doesn\'t parse is 400 MalformedJson, not read as an empty body and refused for its missing name.',
        'steps' => [2 => $problem(400, 'MalformedJson', 'The request body is not valid JSON.', $cors + ['x-ratelimit-remaining' => '117'])],
    ],
    'media/page-not-a-number' => [
        'reason' => 'Page "abc" translates to offset=abc, which is 422 InvalidInput, not 400 InvalidPageNumber.',
        'steps' => $badOffset,
    ],
    'media/page-zero' => [
        'reason' => 'Page 0 translates to a negative offset, which is 422 InvalidInput, not 400 InvalidPageNumber.',
        'steps' => $badOffset,
    ],
    'media/untagged-zero' => [
        'reason' => 'Page 0 translates to a negative offset, which is 422 InvalidInput, not 400 InvalidPageNumber.',
        'steps' => $badOffset,
    ],
    'media/with-tags-page-zero' => [
        'reason' => 'Page 0 translates to a negative offset, which is 422 InvalidInput: API 4 answered the first page, as page 0.',
        'steps' => $badOffset,
    ],
    'media/page-per-page-clamped-high' => [
        'reason' => 'A limit over 100 is refused with 422, not quietly cut to the most a page holds (200 in API 4).',
        'steps' => $badLimit,
    ],
    'media/page-per-page-clamped-low' => [
        'reason' => 'A limit under 1 is refused with 422, not quietly raised to 1.',
        'steps' => $badLimit,
    ],
    'media/danbooru-upstream-failures' => [
        'reason' => 'The gallery\'s settings are named with its GALLERY_ prefix, which the failure\'s advice names.',
        'steps' => [
            0 => $problem(
                500,
                'DanbooruAuthFailed',
                'Danbooru rejected the request. Check GALLERY_DANBOORU_LOGIN and GALLERY_DANBOORU_API_KEY in the server .env, and the IP allowlist on the API key — keys are revoked when the account password changes, and a new outbound address (enabling IPv6 gives the server one) is refused until it is allowlisted.',
                $write,
            ),
        ],
    ],
];

/*
 * Later API versions answer these cases differently on purpose, too much for
 * step-by-step divergences, so they are set aside: the tests named say what the
 * app answers. A field only added since the recordings (a validation failure's
 * `errors`, a media page's `next_cursor`) is a rule in Replay instead, as are
 * the ones above.
 */
$superseded = static fn(string $reason): array => ['superseded' => $reason];
$missingRecord = $superseded('A path id is digits and names a record that exists, or the request is a 404 with the resource\'s code (MediaNotFound, TagNotFound, CategoryNotFound, MappingNotFound, ImplicationNotFound), where API 4 answered 400 for a malformed id, 200 or 204 for a missing one, and used several codes. tests/Feature/Http/Api5Test.php.');
$danbooru = $superseded('The Danbooru import returns no `all_tags` (every tag in the gallery). tests/Feature/Http/Api5Test.php.');
$upload = $superseded('An upload queues its thumbnails and Danbooru lookup: a file says tags_status "queued" (or why not, as tags_error), GET /media/tag-imports reports the outcome, and total_tags_applied is gone. tests/Feature/Http/UploadQueueTest.php.');
$partial = $superseded('A PUT or PATCH changes only the fields it sends; API 4 reset the rest to their defaults. tests/Feature/Http/Api5Test.php.');
$accounts = $superseded('API 6 has accounts: login takes a username and password (MissingCredentials 400, InvalidCredentials 401); the shared GALLERY_ADMIN_PASSWORD, and AdminNotConfigured with it, are gone. API 7 signs the browser in with a session cookie and answers { user }, with no token. tests/Feature/Http/AccountsTest.php.');

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
    'system/docs' => $superseded('GET /docs is Swagger UI, from the gallery\'s own files under its Content-Security-Policy, not Scalar from a CDN. tests/Feature/Http/SecurityHeadersTest.php.'),
];
