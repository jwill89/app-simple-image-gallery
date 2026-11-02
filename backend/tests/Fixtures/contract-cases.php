<?php

declare(strict_types=1);

/**
 * The cases bin/record-contract.php records into tests/Fixtures/contract/.
 *
 * Each case runs on a fresh copy of the fixture (tests/Support/schema.sql +
 * contract.sql, tests/Fixtures/media and dupes) with an empty response cache
 * and rate-limit table. A case is
 *   id      the recording's path under contract/, without .json
 *   route   its row in the endpoint inventory (Recorder::ROUTES), or a
 *           cross-cutting key (Recorder::CROSS_CUTTING)
 *   about   what the recording shows, and anything surprising in it
 *   steps   requests, built with Request (see that class for the options)
 * and optionally
 *   env      .env overrides, e.g. ['GALLERY_ADMIN_PASSWORD' => '']
 *   sql      statements run on the fresh database before the steps
 *   files    paths under the app root to write (string) or remove (null)
 *   danbooru 'down' to stop the Danbooru stub for this case
 *   probe    true to read GET /media/count and GET /tags (one cached read per
 *            cache group) before and after the steps, showing which groups the
 *            write invalidated: X-Cache MISS afterwards means invalidated
 */

use Gallery\Tests\Support\Contract\Request as R;

$tooLongTag = str_repeat('a', 101);
$tooLongCategory = str_repeat('c', 51);

return [
    // ── System ──────────────────────────────────────────────────────────────
    [
        'id' => 'system/version',
        'route' => 'GET /version',
        'about' => 'Application and API versions.',
        'steps' => [R::get('/api/version')],
    ],
    [
        'id' => 'system/openapi',
        'route' => 'GET /openapi.json',
        'about' => 'The committed spec, byte for byte, with a 5-minute public Cache-Control.',
        'steps' => [R::get('/api/openapi.json')],
    ],
    [
        'id' => 'system/openapi-missing',
        'route' => 'GET /openapi.json',
        'about' => 'With no spec file on disk: 404 SpecNotFound.',
        'files' => ['openapi.json' => null],
        'steps' => [R::get('/api/openapi.json')],
    ],
    [
        'id' => 'system/docs',
        'route' => 'GET /docs',
        'about' => 'The Scalar reference page, pointed at /api/openapi.json.',
        'steps' => [R::get('/api/docs')],
    ],

    // ── Auth ────────────────────────────────────────────────────────────────
    [
        'id' => 'auth/login',
        'route' => 'POST /auth/login',
        'about' => 'The admin password returns a token, and that token authorizes a protected write.',
        'steps' => [
            R::post('/api/auth/login', ['auth' => 'none', 'json' => ['password' => 'contract-password'], 'capture' => ['token' => 'token']]),
            R::post('/api/tags', ['auth' => 'captured:token', 'json' => ['tag_name' => 'made after login']]),
        ],
    ],
    [
        'id' => 'auth/login-wrong-password',
        'route' => 'POST /auth/login',
        'about' => 'A wrong password: 401 InvalidPassword.',
        'steps' => [R::post('/api/auth/login', ['auth' => 'none', 'json' => ['password' => 'nope']])],
    ],
    [
        'id' => 'auth/login-missing-password',
        'route' => 'POST /auth/login',
        'about' => 'No password field: also 401 InvalidPassword (an empty string is compared).',
        'steps' => [R::post('/api/auth/login', ['auth' => 'none', 'json' => (object) []])],
    ],
    [
        'id' => 'auth/login-not-configured',
        'route' => 'POST /auth/login',
        'about' => 'GALLERY_ADMIN_PASSWORD unset: 503 AdminNotConfigured, whatever the password.',
        'env' => ['GALLERY_ADMIN_PASSWORD' => ''],
        'steps' => [R::post('/api/auth/login', ['auth' => 'none', 'json' => ['password' => 'changeme']])],
    ],
    [
        'id' => 'auth/login-throttled',
        'route' => 'POST /auth/login',
        'about' => 'Ten attempts are allowed; the eleventh is 429 TooManyAttempts with Retry-After, even with the right password. '
            . 'The limiter counts every attempt, not only failures.',
        'steps' => [
            R::post('/api/auth/login', ['auth' => 'none', 'json' => ['password' => 'nope'], 'repeat' => 10]),
            R::post('/api/auth/login', ['auth' => 'none', 'json' => ['password' => 'nope']]),
            R::post('/api/auth/login', ['auth' => 'none', 'json' => ['password' => 'contract-password']]),
        ],
    ],
    [
        'id' => 'auth/login-foreign-origin',
        'route' => 'POST /auth/login',
        'about' => 'Login is a write, so the CSRF origin check applies: 403 ForbiddenOrigin.',
        'steps' => [R::post('/api/auth/login', ['auth' => 'none', 'origin' => 'foreign', 'json' => ['password' => 'contract-password']])],
    ],

    // ── Media listings ──────────────────────────────────────────────────────
    [
        'id' => 'media/page-default',
        'route' => 'GET /media/page/{page}/{per_page?}',
        'about' => 'Page 1 at the default 40 per page: all 30 items, newest first, ties broken by media_id descending.',
        'steps' => [R::get('/api/media/page/1')],
    ],
    [
        'id' => 'media/page-per-page',
        'route' => 'GET /media/page/{page}/{per_page?}',
        'about' => '10 per page: 3 pages.',
        'steps' => [R::get('/api/media/page/1/10')],
    ],
    [
        'id' => 'media/page-last',
        'route' => 'GET /media/page/{page}/{per_page?}',
        'about' => 'The last page.',
        'steps' => [R::get('/api/media/page/3/10')],
    ],
    [
        'id' => 'media/page-past-end',
        'route' => 'GET /media/page/{page}/{per_page?}',
        'about' => 'A page past the end: 200 with no items.',
        'steps' => [R::get('/api/media/page/4/10')],
    ],
    [
        'id' => 'media/page-zero',
        'route' => 'GET /media/page/{page}/{per_page?}',
        'about' => 'Page 0: 400 InvalidPageNumber.',
        'steps' => [R::get('/api/media/page/0')],
    ],
    [
        'id' => 'media/page-not-a-number',
        'route' => 'GET /media/page/{page}/{per_page?}',
        'about' => 'A non-numeric page reads as 0: 400 InvalidPageNumber, not 404.',
        'steps' => [R::get('/api/media/page/abc')],
    ],
    [
        'id' => 'media/page-per-page-clamped-low',
        'route' => 'GET /media/page/{page}/{per_page?}',
        'about' => 'per_page 0 is clamped to 1.',
        'steps' => [R::get('/api/media/page/2/0')],
    ],
    [
        'id' => 'media/page-per-page-clamped-high',
        'route' => 'GET /media/page/{page}/{per_page?}',
        'about' => 'per_page 500 is clamped to 200.',
        'steps' => [R::get('/api/media/page/1/500')],
    ],
    [
        'id' => 'media/page-cached',
        'route' => 'GET /media/page/{page}/{per_page?}',
        'about' => 'The second identical read is served from the response cache.',
        'steps' => [R::get('/api/media/page/1/5'), R::get('/api/media/page/1/5')],
    ],
    [
        'id' => 'media/page-empty-gallery',
        'route' => 'GET /media/page/{page}/{per_page?}',
        'about' => 'No media at all: total_pages is 0 here, where the untagged and tag-search listings say 1.',
        'sql' => ['DELETE FROM media'],
        'steps' => [R::get('/api/media/page/1')],
    ],
    [
        'id' => 'media/untagged',
        'route' => 'GET /media/untagged/{page}/{per_page?}',
        'about' => 'The four untagged items.',
        'steps' => [R::get('/api/media/untagged/1')],
    ],
    [
        'id' => 'media/untagged-paged',
        'route' => 'GET /media/untagged/{page}/{per_page?}',
        'about' => 'Page 2 at 3 per page.',
        'steps' => [R::get('/api/media/untagged/2/3')],
    ],
    [
        'id' => 'media/untagged-zero',
        'route' => 'GET /media/untagged/{page}/{per_page?}',
        'about' => 'Page 0: 400 InvalidPageNumber.',
        'steps' => [R::get('/api/media/untagged/0')],
    ],
    [
        'id' => 'media/untagged-none',
        'route' => 'GET /media/untagged/{page}/{per_page?}',
        'about' => 'Nothing untagged: no items, total_pages 1.',
        'sql' => ['DELETE FROM media WHERE media_id IN (11, 17, 22, 27)'],
        'steps' => [R::get('/api/media/untagged/1')],
    ],
    [
        'id' => 'media/with-tags',
        'route' => 'GET /media/with-tags/{tag_list}/{page}/{per_page?}',
        'about' => 'One tag.',
        'steps' => [R::get('/api/media/with-tags/cat/1')],
    ],
    [
        'id' => 'media/with-tags-all-of',
        'route' => 'GET /media/with-tags/{tag_list}/{page}/{per_page?}',
        'about' => 'Several tags match media carrying all of them. The list is encoded as the frontend sends it (encodeURIComponent).',
        'steps' => [R::get('/api/media/with-tags/cat%2Cartist%20one/1')],
    ],
    [
        'id' => 'media/with-tags-raw-comma',
        'route' => 'GET /media/with-tags/{tag_list}/{page}/{per_page?}',
        'about' => 'The same search with an unencoded comma.',
        'steps' => [R::get('/api/media/with-tags/cat,artist%20one/1')],
    ],
    [
        'id' => 'media/with-tags-exclude',
        'route' => 'GET /media/with-tags/{tag_list}/{page}/{per_page?}',
        'about' => 'A leading - excludes a tag.',
        'steps' => [R::get('/api/media/with-tags/cat%2C-kitten/1')],
    ],
    [
        'id' => 'media/with-tags-exclude-only',
        'route' => 'GET /media/with-tags/{tag_list}/{page}/{per_page?}',
        'about' => 'Only an exclusion: everything without the tag, untagged media included.',
        'steps' => [R::get('/api/media/with-tags/-animal/1')],
    ],
    [
        'id' => 'media/with-tags-case-insensitive',
        'route' => 'GET /media/with-tags/{tag_list}/{page}/{per_page?}',
        'about' => 'Tag names match case-insensitively (the tags.tag_name column is COLLATE NOCASE).',
        'steps' => [R::get('/api/media/with-tags/CAT/1')],
    ],
    [
        'id' => 'media/with-tags-unknown-skipped',
        'route' => 'GET /media/with-tags/{tag_list}/{page}/{per_page?}',
        'about' => 'An unknown name is dropped silently: the result is the same as for the known tag alone.',
        'steps' => [R::get('/api/media/with-tags/cat%2Cno-such-tag/1')],
    ],
    [
        'id' => 'media/with-tags-none-valid',
        'route' => 'GET /media/with-tags/{tag_list}/{page}/{per_page?}',
        'about' => 'No name matches a tag: 404 NoValidTagsSupplied.',
        'steps' => [R::get('/api/media/with-tags/no-such-tag/1')],
    ],
    [
        'id' => 'media/with-tags-separators-only',
        'route' => 'GET /media/with-tags/{tag_list}/{page}/{per_page?}',
        'about' => 'A list of only a comma, or only a bare -, names nothing: 404 NoValidTagsSupplied.',
        'steps' => [R::get('/api/media/with-tags/%2C/1'), R::get('/api/media/with-tags/-/1')],
    ],
    [
        'id' => 'media/with-tags-plus',
        'route' => 'GET /media/with-tags/{tag_list}/{page}/{per_page?}',
        'about' => '"c++" encoded (%2B) and raw: a + in a path is a plus, not a space.',
        'steps' => [R::get('/api/media/with-tags/c%2B%2B/1'), R::get('/api/media/with-tags/c++/1')],
    ],
    [
        'id' => 'media/with-tags-punctuation',
        'route' => 'GET /media/with-tags/{tag_list}/{page}/{per_page?}',
        'about' => '"k-on!": a hyphen inside a name is not an exclusion.',
        'steps' => [R::get('/api/media/with-tags/k-on!/1')],
    ],
    [
        'id' => 'media/with-tags-unicode',
        'route' => 'GET /media/with-tags/{tag_list}/{page}/{per_page?}',
        'about' => '"café", percent-encoded UTF-8.',
        'steps' => [R::get('/api/media/with-tags/caf%C3%A9/1')],
    ],
    [
        'id' => 'media/with-tags-whitespace',
        'route' => 'GET /media/with-tags/{tag_list}/{page}/{per_page?}',
        'about' => 'Spaces around names and after a - are trimmed.',
        'steps' => [R::get('/api/media/with-tags/%20cat%20%2C%20-%20kitten%20/1')],
    ],
    [
        'id' => 'media/with-tags-paged',
        'route' => 'GET /media/with-tags/{tag_list}/{page}/{per_page?}',
        'about' => 'Page 2 at 3 per page.',
        'steps' => [R::get('/api/media/with-tags/cat/2/3')],
    ],
    [
        'id' => 'media/with-tags-page-zero',
        'route' => 'GET /media/with-tags/{tag_list}/{page}/{per_page?}',
        'about' => 'Unlike the other listings, page 0 is not rejected here: it is answered with current_page 0.',
        'steps' => [R::get('/api/media/with-tags/cat/0')],
    ],
    [
        'id' => 'media/with-tags-cached',
        'route' => 'GET /media/with-tags/{tag_list}/{page}/{per_page?}',
        'about' => 'Cached by the raw tag list: a repeat is a HIT, but the same search in other letter case is a separate MISS.',
        'steps' => [R::get('/api/media/with-tags/cat/1'), R::get('/api/media/with-tags/cat/1'), R::get('/api/media/with-tags/CAT/1')],
    ],
    [
        'id' => 'media/random',
        'route' => 'GET /media/random',
        'about' => 'A random item, recorded with one item left so the answer is fixed.',
        'sql' => ['DELETE FROM media WHERE media_id <> 6'],
        'steps' => [R::get('/api/media/random')],
    ],
    [
        'id' => 'media/random-empty',
        'route' => 'GET /media/random',
        'about' => 'No media: 404 MediaNotFound.',
        'sql' => ['DELETE FROM media'],
        'steps' => [R::get('/api/media/random')],
    ],
    [
        'id' => 'media/count',
        'route' => 'GET /media/count',
        'about' => 'The total, cached: MISS then HIT.',
        'steps' => [R::get('/api/media/count'), R::get('/api/media/count')],
    ],

    // ── Media batch reads and deletes ───────────────────────────────────────
    [
        'id' => 'media/by-ids',
        'route' => 'POST /media/by-ids',
        'about' => 'Public (no token). Numeric strings count; unknown, zero and negative ids are skipped; the order is the listing order, not the request order.',
        'steps' => [R::post('/api/media/by-ids', ['auth' => 'none', 'json' => ['ids' => [3, 1, 999, '5', -2, 0, 3]]])],
    ],
    [
        'id' => 'media/by-ids-empty',
        'route' => 'POST /media/by-ids',
        'about' => 'An empty list: 400 InvalidInput.',
        'steps' => [R::post('/api/media/by-ids', ['auth' => 'none', 'json' => ['ids' => []]])],
    ],
    [
        'id' => 'media/by-ids-not-a-list',
        'route' => 'POST /media/by-ids',
        'about' => 'ids not an array, and no body at all: 400 InvalidInput.',
        'steps' => [
            R::post('/api/media/by-ids', ['auth' => 'none', 'json' => ['ids' => 5]]),
            R::post('/api/media/by-ids', ['auth' => 'none']),
        ],
    ],
    [
        'id' => 'media/by-ids-none-valid',
        'route' => 'POST /media/by-ids',
        'about' => 'Only zero, negative and non-numeric ids: 400 InvalidInput with a different message.',
        'steps' => [R::post('/api/media/by-ids', ['auth' => 'none', 'json' => ['ids' => [0, -1, 'x']]])],
    ],
    [
        'id' => 'media/by-ids-foreign-origin',
        'route' => 'POST /media/by-ids',
        'about' => 'Public, but still a POST: the CSRF origin check applies.',
        'steps' => [R::post('/api/media/by-ids', ['auth' => 'none', 'origin' => 'foreign', 'json' => ['ids' => [1]]])],
    ],
    [
        'id' => 'media/bulk-delete',
        'route' => 'POST /media/bulk-delete',
        'about' => 'Deletes what it can and lists the rest as failed; invalidates media and tags.',
        'probe' => true,
        'steps' => [
            R::post('/api/media/bulk-delete', ['json' => ['media_ids' => [11, 17, 999, 0]]]),
            R::get('/api/media/11'),
        ],
    ],
    [
        'id' => 'media/bulk-delete-none-found',
        'route' => 'POST /media/bulk-delete',
        'about' => 'Nothing deleted: still 200, and no cache group is invalidated.',
        'probe' => true,
        'steps' => [R::post('/api/media/bulk-delete', ['json' => ['media_ids' => [999]]])],
    ],
    [
        'id' => 'media/bulk-delete-empty',
        'route' => 'POST /media/bulk-delete',
        'about' => 'An empty list: 400 InvalidInput.',
        'steps' => [R::post('/api/media/bulk-delete', ['json' => ['media_ids' => []]])],
    ],
    [
        'id' => 'media/bulk-delete-no-token',
        'route' => 'POST /media/bulk-delete',
        'about' => 'Without a token: 401 Unauthorized.',
        'steps' => [R::post('/api/media/bulk-delete', ['auth' => 'none', 'json' => ['media_ids' => [11]]])],
    ],

    // ── Uploads ─────────────────────────────────────────────────────────────
    [
        'id' => 'media/upload',
        'route' => 'POST /media',
        'about' => 'One PNG: 201 with the per-file result; invalidates media only. The new row has its metadata and perceptual fingerprint '
            . '(computed with GD). Thumbnails need ffmpeg, which the recording host did not have; they are not part of any response.',
        'probe' => true,
        'steps' => [
            R::post('/api/media', ['multipart' => [R::file('uploads/tiny.png') + ['type' => 'image/png']]]),
            R::get('/api/media/31'),
        ],
    ],
    [
        'id' => 'media/upload-mixed',
        'route' => 'POST /media',
        'about' => 'Six files in one request: accepted JPEG and static GIF, an animated GIF (stored as video), a text file named .png '
            . '(rejected by content), a .txt (rejected by extension) and a copy of media 2 (duplicate). Still 201. The animated GIF\'s '
            . 'width/height/duration come from ffprobe where it is installed; without it, as here, width/height fall back to getimagesize and duration is 0.',
        'steps' => [
            R::post('/api/media', ['multipart' => [
                R::file('uploads/tiny.jpg') + ['type' => 'image/jpeg'],
                R::file('uploads/static.gif') + ['type' => 'image/gif'],
                R::file('uploads/animated.gif') + ['type' => 'image/gif'],
                R::file('uploads/not-an-image.png') + ['type' => 'image/png'],
                R::file('uploads/notes.txt') + ['type' => 'text/plain'],
                R::file('uploads/duplicate.png') + ['type' => 'image/png'],
            ]]),
            R::post('/api/media/by-ids', ['auth' => 'none', 'json' => ['ids' => [31, 32, 33]]]),
        ],
    ],
    [
        'id' => 'media/upload-same-name',
        'route' => 'POST /media',
        'about' => 'Two files with the same name: the second is saved with a _1 suffix.',
        'steps' => [R::post('/api/media', ['multipart' => [
            R::file('uploads/tiny.png') + ['type' => 'image/png'],
            R::file('uploads/tiny.jpg', 'tiny.png') + ['type' => 'image/png'],
        ]])],
    ],
    [
        'id' => 'media/upload-unsafe-name',
        'route' => 'POST /media',
        'about' => 'Characters outside [a-zA-Z0-9._-] in the file name become underscores; the extension keeps its case.',
        'steps' => [R::post('/api/media', ['multipart' => [R::file('uploads/tiny.png', 'my photo #1.PNG') + ['type' => 'image/png']]])],
    ],
    [
        'id' => 'media/upload-duplicate-only',
        'route' => 'POST /media',
        'about' => 'Only a duplicate: 201 with total_uploaded 0, and no cache group invalidated.',
        'probe' => true,
        'steps' => [R::post('/api/media', ['multipart' => [R::file('uploads/duplicate.png') + ['type' => 'image/png']]])],
    ],
    [
        'id' => 'media/upload-fetch-tags',
        'route' => 'POST /media',
        'about' => 'fetch_tags=1 looks the upload up on Danbooru (the stub matches its MD5) and applies the tags: per-file tag fields, '
            . 'total_tags_applied, and the tags group invalidated too.',
        'probe' => true,
        'steps' => [
            R::post('/api/media', ['multipart' => [
                R::file('uploads/tiny.png') + ['type' => 'image/png'],
                ['name' => 'fetch_tags', 'value' => '1'],
            ]]),
            R::get('/api/media/31/tags'),
        ],
    ],
    [
        'id' => 'media/upload-fetch-tags-not-configured',
        'route' => 'POST /media',
        'about' => 'fetch_tags=1 without Danbooru credentials is ignored: no tag fields, no total_tags_applied.',
        'env' => ['DANBOORU_LOGIN' => ''],
        'steps' => [R::post('/api/media', ['multipart' => [
            R::file('uploads/tiny.png') + ['type' => 'image/png'],
            ['name' => 'fetch_tags', 'value' => '1'],
        ]])],
    ],
    [
        'id' => 'media/upload-too-large',
        'route' => 'POST /media',
        'about' => 'A file over upload_max_filesize is reported per file as "Upload error code: 1" (UPLOAD_ERR_INI_SIZE). '
            . 'The recording server sets the limit to 1M so a 1.5 MB file trips it; production allows 100M.',
        'steps' => [R::post('/api/media', ['multipart' => [['name' => 'files[]', 'bytes' => 1_500_000, 'filename' => 'big.png', 'type' => 'image/png']]])],
    ],
    [
        'id' => 'media/upload-no-files',
        'route' => 'POST /media',
        'about' => 'A multipart body with no files: 400 NoFilesUploaded.',
        'steps' => [R::post('/api/media', ['multipart' => [['name' => 'fetch_tags', 'value' => '0']]])],
    ],
    [
        'id' => 'media/upload-no-token',
        'route' => 'POST /media',
        'about' => 'Without a token: 401 Unauthorized.',
        'steps' => [R::post('/api/media', ['auth' => 'none', 'multipart' => [R::file('uploads/tiny.png') + ['type' => 'image/png']]])],
    ],

    // ── Single media item ───────────────────────────────────────────────────
    [
        'id' => 'media/show',
        'route' => 'GET /media/{media_id}',
        'about' => 'An image and a video.',
        'steps' => [R::get('/api/media/1'), R::get('/api/media/5')],
    ],
    [
        'id' => 'media/show-not-found',
        'route' => 'GET /media/{media_id}',
        'about' => 'An unknown id: 404 MediaNotFound.',
        'steps' => [R::get('/api/media/999')],
    ],
    [
        'id' => 'media/show-invalid-id',
        'route' => 'GET /media/{media_id}',
        'about' => 'Zero, negative and non-numeric ids: 400 InvalidMediaID. The route takes any segment, so a non-number is a 400, not a 404.',
        'steps' => [R::get('/api/media/0'), R::get('/api/media/-1'), R::get('/api/media/abc')],
    ],
    [
        'id' => 'media/delete',
        'route' => 'DELETE /media/{media_id}',
        'about' => '204; the item and its tag links are gone; invalidates media and tags.',
        'probe' => true,
        'steps' => [R::delete('/api/media/1'), R::get('/api/media/1'), R::get('/api/media/1/tags')],
    ],
    [
        'id' => 'media/delete-not-found',
        'route' => 'DELETE /media/{media_id}',
        'about' => 'An unknown id: 404 MediaNotFound.',
        'steps' => [R::delete('/api/media/999')],
    ],
    [
        'id' => 'media/delete-invalid-id',
        'route' => 'DELETE /media/{media_id}',
        'about' => 'Id 0: 400 InvalidMediaID.',
        'steps' => [R::delete('/api/media/0')],
    ],
    [
        'id' => 'media/delete-no-token',
        'route' => 'DELETE /media/{media_id}',
        'about' => 'Without a token, or with an expired one: 401. DELETE /media/{id}/tags/{tag_id} is public; this is not.',
        'steps' => [R::delete('/api/media/1', ['auth' => 'none']), R::delete('/api/media/1', ['auth' => 'expired'])],
    ],

    // ── Media tags ──────────────────────────────────────────────────────────
    [
        'id' => 'media/tags',
        'route' => 'GET /media/{media_id}/tags',
        'about' => 'Ordered by category sort_order, then name; cached per item.',
        'steps' => [R::get('/api/media/1/tags'), R::get('/api/media/1/tags')],
    ],
    [
        'id' => 'media/tags-untagged',
        'route' => 'GET /media/{media_id}/tags',
        'about' => 'An untagged item: an empty list.',
        'steps' => [R::get('/api/media/11/tags')],
    ],
    [
        'id' => 'media/tags-not-found',
        'route' => 'GET /media/{media_id}/tags',
        'about' => 'An unknown item: 404 MediaDoesNotExist; id 0: 400 InvalidMediaID.',
        'steps' => [R::get('/api/media/999/tags'), R::get('/api/media/0/tags')],
    ],
    [
        'id' => 'media/tags-add',
        'route' => 'PATCH /media/{media_id}/tags',
        'about' => 'Public (no token). kitten brings cat and animal with it (kitten → cat → animal); returns the item\'s tags; invalidates media and tags.',
        'probe' => true,
        'steps' => [R::patch('/api/media/11/tags', ['auth' => 'none', 'json' => ['tag_ids' => [5]]])],
    ],
    [
        'id' => 'media/tags-add-existing',
        'route' => 'PATCH /media/{media_id}/tags',
        'about' => 'A tag the item already has: 200, unchanged.',
        'steps' => [R::patch('/api/media/1/tags', ['auth' => 'none', 'json' => ['tag_ids' => [3]]])],
    ],
    [
        'id' => 'media/tags-add-scalar',
        'route' => 'PATCH /media/{media_id}/tags',
        'about' => 'tag_ids as a single number rather than a list is accepted.',
        'steps' => [R::patch('/api/media/11/tags', ['auth' => 'none', 'json' => ['tag_ids' => 8]])],
    ],
    [
        'id' => 'media/tags-add-invalid',
        'route' => 'PATCH /media/{media_id}/tags',
        'about' => 'An empty list: 400 InvalidTagList. An unknown tag among known ones: 404 TagDoesNotExist naming it, and nothing applied.',
        'steps' => [
            R::patch('/api/media/11/tags', ['auth' => 'none', 'json' => ['tag_ids' => []]]),
            R::patch('/api/media/11/tags', ['auth' => 'none', 'json' => ['tag_ids' => [8, 999]]]),
            R::get('/api/media/11/tags'),
        ],
    ],
    [
        'id' => 'media/tags-add-media-not-found',
        'route' => 'PATCH /media/{media_id}/tags',
        'about' => 'An unknown item: 404 MediaDoesNotExist; id 0: 400 InvalidMediaID.',
        'steps' => [
            R::patch('/api/media/999/tags', ['auth' => 'none', 'json' => ['tag_ids' => [8]]]),
            R::patch('/api/media/0/tags', ['auth' => 'none', 'json' => ['tag_ids' => [8]]]),
        ],
    ],
    [
        'id' => 'media/tags-add-foreign-origin',
        'route' => 'PATCH /media/{media_id}/tags',
        'about' => 'Public, but the CSRF origin check still applies.',
        'steps' => [R::patch('/api/media/11/tags', ['auth' => 'none', 'origin' => 'foreign', 'json' => ['tag_ids' => [8]]])],
    ],
    [
        'id' => 'media/tags-remove',
        'route' => 'DELETE /media/{media_id}/tags/{tag_id}',
        'about' => 'Public (no token). Removes only that tag: animal, implied by cat, stays. Returns the item\'s tags; invalidates media and tags.',
        'probe' => true,
        'steps' => [R::delete('/api/media/1/tags/3', ['auth' => 'none'])],
    ],
    [
        'id' => 'media/tags-remove-not-applied',
        'route' => 'DELETE /media/{media_id}/tags/{tag_id}',
        'about' => 'A tag the item does not have: 200, unchanged.',
        'steps' => [R::delete('/api/media/1/tags/12', ['auth' => 'none'])],
    ],
    [
        'id' => 'media/tags-remove-not-found',
        'route' => 'DELETE /media/{media_id}/tags/{tag_id}',
        'about' => 'An unknown tag: 404 CouldNotFindTag. An unknown item: 404 MediaDoesNotExist.',
        'steps' => [R::delete('/api/media/1/tags/999', ['auth' => 'none']), R::delete('/api/media/999/tags/3', ['auth' => 'none'])],
    ],
    [
        'id' => 'media/tags-remove-invalid-id',
        'route' => 'DELETE /media/{media_id}/tags/{tag_id}',
        'about' => 'Tag id 0: 400 InvalidTagID. Media id 0: 400 InvalidMediaID.',
        'steps' => [R::delete('/api/media/1/tags/0', ['auth' => 'none']), R::delete('/api/media/0/tags/3', ['auth' => 'none'])],
    ],
    [
        'id' => 'media/tags-remove-foreign-origin',
        'route' => 'DELETE /media/{media_id}/tags/{tag_id}',
        'about' => 'Public, but the CSRF origin check still applies.',
        'steps' => [R::delete('/api/media/1/tags/3', ['auth' => 'none', 'origin' => 'foreign'])],
    ],

    // ── Danbooru import for one item (against the stub; see danbooru-stub.php) ──
    [
        'id' => 'media/danbooru-md5',
        'route' => 'POST /media/{media_id}/danbooru-tags',
        'about' => 'Found by MD5. Mapped categories only (meta is not mapped); cat_ears is renamed by the tag map; new names are created '
            . 'in their mapped category. Implications are not applied. Invalidates media and tags.',
        'probe' => true,
        'steps' => [R::post('/api/media/1/danbooru-tags')],
    ],
    [
        'id' => 'media/danbooru-iqdb',
        'route' => 'POST /media/{media_id}/danbooru-tags',
        'about' => 'MD5 misses; IQDB finds the post by the file\'s public URL (GALLERY_URL/media/full/<file>).',
        'steps' => [R::post('/api/media/6/danbooru-tags')],
    ],
    [
        'id' => 'media/danbooru-post-id',
        'route' => 'POST /media/{media_id}/danbooru-tags',
        'about' => 'A given danbooru_post_id is fetched directly, with no MD5 or IQDB lookup.',
        'steps' => [R::post('/api/media/11/danbooru-tags', ['json' => ['danbooru_post_id' => 1002]])],
    ],
    [
        'id' => 'media/danbooru-not-found',
        'route' => 'POST /media/{media_id}/danbooru-tags',
        'about' => 'Neither MD5 nor IQDB matches: 404 NotFoundOnDanbooru. For a video IQDB is skipped.',
        'steps' => [R::post('/api/media/10/danbooru-tags'), R::post('/api/media/5/danbooru-tags')],
    ],
    [
        'id' => 'media/danbooru-post-not-found',
        'route' => 'POST /media/{media_id}/danbooru-tags',
        'about' => 'An unknown danbooru_post_id: 404 NotFoundOnDanbooru, naming the post.',
        'steps' => [R::post('/api/media/1/danbooru-tags', ['json' => ['danbooru_post_id' => 9999]])],
    ],
    [
        'id' => 'media/danbooru-upstream-failures',
        'route' => 'POST /media/{media_id}/danbooru-tags',
        'about' => 'Danbooru answering 401, 429 and 500 maps to 500 DanbooruAuthFailed, 429 DanbooruRateLimited and 503 DanbooruUnavailable. Never 502/504 (Cloudflare).',
        'steps' => [
            R::post('/api/media/15/danbooru-tags'),
            R::post('/api/media/16/danbooru-tags'),
            R::post('/api/media/18/danbooru-tags'),
        ],
    ],
    [
        'id' => 'media/danbooru-unreachable',
        'route' => 'POST /media/{media_id}/danbooru-tags',
        'about' => 'Danbooru unreachable (stub stopped): 503 DanbooruUnreachable.',
        'danbooru' => 'down',
        'steps' => [R::post('/api/media/1/danbooru-tags')],
    ],
    [
        'id' => 'media/danbooru-not-configured',
        'route' => 'POST /media/{media_id}/danbooru-tags',
        'about' => 'No Danbooru credentials: 500 DanbooruNotConfigured.',
        'env' => ['DANBOORU_API_KEY' => ''],
        'steps' => [R::post('/api/media/1/danbooru-tags')],
    ],
    [
        'id' => 'media/danbooru-media-not-found',
        'route' => 'POST /media/{media_id}/danbooru-tags',
        'about' => 'An unknown item: 404 MediaDoesNotExist; id 0: 400 InvalidMediaID.',
        'steps' => [R::post('/api/media/999/danbooru-tags'), R::post('/api/media/0/danbooru-tags')],
    ],
    [
        'id' => 'media/danbooru-no-token',
        'route' => 'POST /media/{media_id}/danbooru-tags',
        'about' => 'Without a token: 401 Unauthorized.',
        'steps' => [R::post('/api/media/1/danbooru-tags', ['auth' => 'none'])],
    ],

    // ── Tags ────────────────────────────────────────────────────────────────
    [
        'id' => 'tags/index',
        'route' => 'GET /tags',
        'about' => 'All tags by name; cached.',
        'steps' => [R::get('/api/tags'), R::get('/api/tags')],
    ],
    [
        'id' => 'tags/display',
        'route' => 'GET /tags/display',
        'about' => 'Tags with category and usage/implication counts, by category sort_order then name.',
        'steps' => [R::get('/api/tags/display')],
    ],
    [
        'id' => 'tags/show',
        'route' => 'GET /tags/{tag_id}',
        'about' => 'One tag.',
        'steps' => [R::get('/api/tags/3')],
    ],
    [
        'id' => 'tags/show-not-found',
        'route' => 'GET /tags/{tag_id}',
        'about' => 'An unknown id: 404 TagDoesNotExist. Zero and non-numeric ids: 400 InvalidTagID.',
        'steps' => [R::get('/api/tags/999'), R::get('/api/tags/0'), R::get('/api/tags/abc')],
    ],
    [
        'id' => 'tags/create',
        'route' => 'POST /tags',
        'about' => '201 with the tag; the name is trimmed and lowercased. Invalidates tags only.',
        'probe' => true,
        'steps' => [R::post('/api/tags', ['json' => ['tag_name' => '  New Tag  ', 'category_id' => 2]])],
    ],
    [
        'id' => 'tags/create-default-category',
        'route' => 'POST /tags',
        'about' => 'Without category_id the tag goes in category 1.',
        'steps' => [R::post('/api/tags', ['json' => ['tag_name' => 'plain']])],
    ],
    [
        'id' => 'tags/create-sanitized',
        'route' => 'POST /tags',
        'about' => 'HTML is stripped, multibyte letters lowercased, and a leading - (a search exclusion) dropped.',
        'steps' => [R::post('/api/tags', ['json' => ['tag_name' => '-<b>ÉTÉ</b>']])],
    ],
    [
        'id' => 'tags/create-form-encoded',
        'route' => 'POST /tags',
        'about' => 'A form-encoded body works as well as JSON.',
        'steps' => [R::post('/api/tags', ['form' => ['tag_name' => 'formed', 'category_id' => '3']])],
    ],
    [
        'id' => 'tags/create-invalid-name',
        'route' => 'POST /tags',
        'about' => 'Blank, only dashes, malformed JSON (no fields) → 400 InvalidTagName; 101 characters → 400 TagNameTooLong.',
        'steps' => [
            R::post('/api/tags', ['json' => ['tag_name' => '   ']]),
            R::post('/api/tags', ['json' => ['tag_name' => '---']]),
            R::post('/api/tags', ['raw' => '{"tag_name": ']),
            R::post('/api/tags', ['json' => ['tag_name' => $tooLongTag]]),
        ],
    ],
    [
        'id' => 'tags/create-invalid-category',
        'route' => 'POST /tags',
        'about' => 'An unknown category: 400 InvalidCategoryID.',
        'steps' => [R::post('/api/tags', ['json' => ['tag_name' => 'x', 'category_id' => 99]])],
    ],
    [
        'id' => 'tags/create-exists',
        'route' => 'POST /tags',
        'about' => 'A name that exists in other letter case: 400 TagAlreadyExists, quoting the lowercased name.',
        'steps' => [R::post('/api/tags', ['json' => ['tag_name' => 'CAT']])],
    ],
    [
        'id' => 'tags/create-unauthorized',
        'route' => 'POST /tags',
        'about' => 'No token, an expired token, and a header without the Bearer scheme: each 401 Unauthorized.',
        'steps' => [
            R::post('/api/tags', ['auth' => 'none', 'json' => ['tag_name' => 'x']]),
            R::post('/api/tags', ['auth' => 'expired', 'json' => ['tag_name' => 'x']]),
            R::post('/api/tags', ['auth' => 'raw:Token c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00', 'json' => ['tag_name' => 'x']]),
        ],
    ],
    [
        'id' => 'tags/create-origin-check',
        'route' => 'POST /tags',
        'about' => 'CSRF: no Origin or Referer → 403; a foreign Origin → 403, before the token is looked at, with no CORS headers and no '
            . 'X-RateLimit-Remaining (the origin check runs outside the rate limiter); a foreign Referer → 403.',
        'steps' => [
            R::post('/api/tags', ['origin' => 'none', 'json' => ['tag_name' => 'x']]),
            R::post('/api/tags', ['origin' => 'foreign', 'json' => ['tag_name' => 'x']]),
            R::post('/api/tags', ['origin' => 'foreign', 'auth' => 'none', 'json' => ['tag_name' => 'x']]),
            R::post('/api/tags', ['origin' => 'none', 'referer' => 'https://evil.example/tags', 'json' => ['tag_name' => 'x']]),
        ],
    ],
    [
        'id' => 'tags/create-referer-fallback',
        'route' => 'POST /tags',
        'about' => 'With no Origin, an allowed Referer passes the CSRF check, including one with a port, path and query.',
        'steps' => [
            R::post('/api/tags', ['origin' => 'none', 'referer' => 'https://gallery.test/tags', 'json' => ['tag_name' => 'via referer']]),
            R::post('/api/tags', ['origin' => 'none', 'referer' => 'http://localhost:5173/tags?page=2', 'json' => ['tag_name' => 'via dev referer']]),
        ],
    ],
    [
        'id' => 'tags/create-dev-origin',
        'route' => 'POST /tags',
        'about' => 'The second allowed origin (the Vite dev server) passes and is echoed in the CORS headers.',
        'steps' => [R::post('/api/tags', ['origin' => 'dev', 'json' => ['tag_name' => 'from dev']])],
    ],
    [
        'id' => 'tags/update',
        'route' => 'PUT /tags/{tag_id}',
        'about' => 'Rename and recategorize: 200 with the tag. Invalidates tags only.',
        'probe' => true,
        'steps' => [R::put('/api/tags/8', ['json' => ['tag_name' => 'Grin', 'category_id' => 3]])],
    ],
    [
        'id' => 'tags/update-default-category',
        'route' => 'PUT /tags/{tag_id}',
        'about' => 'Without category_id the tag is moved to category 1, not left where it was.',
        'steps' => [R::put('/api/tags/13', ['json' => ['tag_name' => 'artist one']])],
    ],
    [
        'id' => 'tags/update-name-taken',
        'route' => 'PUT /tags/{tag_id}',
        'about' => 'Renaming onto an existing name is not checked: the UNIQUE constraint throws and Slim\'s error handler answers 500 with its HTML page.',
        'steps' => [R::put('/api/tags/8', ['json' => ['tag_name' => 'cat', 'category_id' => 1]])],
    ],
    [
        'id' => 'tags/update-not-found',
        'route' => 'PUT /tags/{tag_id}',
        'about' => 'An unknown tag: 404 TagDoesNotExist. Id 0: 400 InvalidTagID.',
        'steps' => [
            R::put('/api/tags/999', ['json' => ['tag_name' => 'x', 'category_id' => 1]]),
            R::put('/api/tags/0', ['json' => ['tag_name' => 'x', 'category_id' => 1]]),
        ],
    ],
    [
        'id' => 'tags/update-invalid',
        'route' => 'PUT /tags/{tag_id}',
        'about' => 'An empty name: 400 InvalidTagName. An unknown category: 400 InvalidCategoryID.',
        'steps' => [
            R::put('/api/tags/8', ['json' => ['tag_name' => '']]),
            R::put('/api/tags/8', ['json' => ['tag_name' => 'smile', 'category_id' => 99]]),
        ],
    ],
    [
        'id' => 'tags/update-no-token',
        'route' => 'PUT /tags/{tag_id}',
        'about' => 'Without a token: 401 Unauthorized.',
        'steps' => [R::put('/api/tags/8', ['auth' => 'none', 'json' => ['tag_name' => 'x', 'category_id' => 1]])],
    ],
    [
        'id' => 'tags/delete',
        'route' => 'DELETE /tags/{tag_id}',
        'about' => '204; the tag, its media links and its implications both ways are gone. Invalidates tags and media.',
        'probe' => true,
        'steps' => [
            R::delete('/api/tags/3'),
            R::get('/api/tags/3'),
            R::get('/api/media/1/tags'),
            R::get('/api/tag-implications'),
        ],
    ],
    [
        'id' => 'tags/delete-not-found',
        'route' => 'DELETE /tags/{tag_id}',
        'about' => 'An unknown tag: 404 TagDoesNotExist. Id 0: 400 InvalidTagID.',
        'steps' => [R::delete('/api/tags/999'), R::delete('/api/tags/0')],
    ],
    [
        'id' => 'tags/delete-no-token',
        'route' => 'DELETE /tags/{tag_id}',
        'about' => 'Without a token: 401 Unauthorized.',
        'steps' => [R::delete('/api/tags/12', ['auth' => 'none'])],
    ],
    [
        'id' => 'tags/delete-migrate',
        'route' => 'DELETE /tags/{tag_id}/migrate-to/{target_tag_id}',
        'about' => 'kitten\'s media move to smile, then kitten is deleted: 204. Invalidates tags and media.',
        'probe' => true,
        'steps' => [R::delete('/api/tags/5/migrate-to/8'), R::get('/api/media/2/tags'), R::get('/api/tags/5')],
    ],
    [
        'id' => 'tags/delete-migrate-to-zero',
        'route' => 'DELETE /tags/{tag_id}/migrate-to/{target_tag_id}',
        'about' => 'A target of 0 means no migration: a plain delete, 204.',
        'steps' => [R::delete('/api/tags/12/migrate-to/0'), R::get('/api/tags/12')],
    ],
    [
        'id' => 'tags/delete-migrate-invalid',
        'route' => 'DELETE /tags/{tag_id}/migrate-to/{target_tag_id}',
        'about' => 'To itself: 400 CannotMigrateToSelf. Unknown target: 404 MigrationTargetDoesNotExist. Unknown source: 404 TagDoesNotExist. '
            . 'Nothing is deleted.',
        'steps' => [
            R::delete('/api/tags/5/migrate-to/5'),
            R::delete('/api/tags/5/migrate-to/999'),
            R::delete('/api/tags/999/migrate-to/5'),
            R::get('/api/tags/5'),
        ],
    ],
    [
        'id' => 'tags/delete-migrate-no-token',
        'route' => 'DELETE /tags/{tag_id}/migrate-to/{target_tag_id}',
        'about' => 'Without a token: 401 Unauthorized.',
        'steps' => [R::delete('/api/tags/5/migrate-to/8', ['auth' => 'none'])],
    ],
    [
        'id' => 'tags/migrate',
        'route' => 'POST /tags/{tag_id}/migrate',
        'about' => 'kitten\'s media move to smile: 200 with the ids. The source tag is kept (only its media links move). Invalidates tags and media.',
        'probe' => true,
        'steps' => [
            R::post('/api/tags/5/migrate', ['json' => ['target_tag_id' => 8]]),
            R::get('/api/tags/5'),
            R::get('/api/media/2/tags'),
        ],
    ],
    [
        'id' => 'tags/migrate-invalid',
        'route' => 'POST /tags/{tag_id}/migrate',
        'about' => 'No target: 400 InvalidTagID. To itself: 400 CannotMigrateToSelf. Unknown target or source: 404 TagDoesNotExist.',
        'steps' => [
            R::post('/api/tags/5/migrate', ['json' => (object) []]),
            R::post('/api/tags/5/migrate', ['json' => ['target_tag_id' => 5]]),
            R::post('/api/tags/5/migrate', ['json' => ['target_tag_id' => 999]]),
            R::post('/api/tags/999/migrate', ['json' => ['target_tag_id' => 5]]),
        ],
    ],
    [
        'id' => 'tags/migrate-no-token',
        'route' => 'POST /tags/{tag_id}/migrate',
        'about' => 'Without a token: 401 Unauthorized.',
        'steps' => [R::post('/api/tags/5/migrate', ['auth' => 'none', 'json' => ['target_tag_id' => 8]])],
    ],

    // ── Tag categories ──────────────────────────────────────────────────────
    [
        'id' => 'tag-categories/index',
        'route' => 'GET /tag-categories',
        'about' => 'All categories by sort_order, then name. Not cached.',
        'steps' => [R::get('/api/tag-categories')],
    ],
    [
        'id' => 'tag-categories/create',
        'route' => 'POST /tag-categories',
        'about' => '201 with the category. Invalidates tags only.',
        'probe' => true,
        'steps' => [R::post('/api/tag-categories', ['json' => ['category_name' => 'Meta', 'color' => 'violet', 'description' => 'Tags about the file itself.', 'sort_order' => 5]])],
    ],
    [
        'id' => 'tag-categories/create-default-color',
        'route' => 'POST /tag-categories',
        'about' => 'Without color the default is "white", which is not a valid colour: 400 InvalidColor. A colour is effectively required.',
        'steps' => [R::post('/api/tag-categories', ['json' => ['category_name' => 'Meta']])],
    ],
    [
        'id' => 'tag-categories/create-invalid',
        'route' => 'POST /tag-categories',
        'about' => 'Blank name: 400 InvalidInput. 51 characters: 400 NameTooLong. A semantic colour (danger): 400 InvalidColor. '
            . 'An existing name in other letter case: 400 NameTaken.',
        'steps' => [
            R::post('/api/tag-categories', ['json' => ['category_name' => ' ', 'color' => 'violet']]),
            R::post('/api/tag-categories', ['json' => ['category_name' => $tooLongCategory, 'color' => 'violet']]),
            R::post('/api/tag-categories', ['json' => ['category_name' => 'Meta', 'color' => 'danger']]),
            R::post('/api/tag-categories', ['json' => ['category_name' => 'artist', 'color' => 'rose']]),
        ],
    ],
    [
        'id' => 'tag-categories/create-no-token',
        'route' => 'POST /tag-categories',
        'about' => 'Without a token: 401 Unauthorized.',
        'steps' => [R::post('/api/tag-categories', ['auth' => 'none', 'json' => ['category_name' => 'Meta', 'color' => 'violet']])],
    ],
    [
        'id' => 'tag-categories/update',
        'route' => 'PUT /tag-categories/{category_id}',
        'about' => '200 with the category. Invalidates tags only.',
        'probe' => true,
        'steps' => [R::put('/api/tag-categories/2', ['json' => ['category_name' => 'Artists', 'color' => 'orange', 'description' => 'Who made it.', 'sort_order' => 1]])],
    ],
    [
        'id' => 'tag-categories/update-partial',
        'route' => 'PUT /tag-categories/{category_id}',
        'about' => 'Its own name in other letter case is not a conflict. Fields left out are reset to their defaults (description "", sort_order 0), not kept.',
        'steps' => [R::put('/api/tag-categories/2', ['json' => ['category_name' => 'ARTIST', 'color' => 'rose']])],
    ],
    [
        'id' => 'tag-categories/update-invalid',
        'route' => 'PUT /tag-categories/{category_id}',
        'about' => 'Another category\'s name: 400 NameTaken. No fields: 400 InvalidInput. Unknown id: 404 CategoryNotFound. Id 0: 400 InvalidInput.',
        'steps' => [
            R::put('/api/tag-categories/2', ['json' => ['category_name' => 'General', 'color' => 'rose']]),
            R::put('/api/tag-categories/2', ['json' => (object) []]),
            R::put('/api/tag-categories/999', ['json' => ['category_name' => 'X', 'color' => 'rose']]),
            R::put('/api/tag-categories/0', ['json' => ['category_name' => 'X', 'color' => 'rose']]),
        ],
    ],
    [
        'id' => 'tag-categories/update-no-token',
        'route' => 'PUT /tag-categories/{category_id}',
        'about' => 'Without a token: 401 Unauthorized.',
        'steps' => [R::put('/api/tag-categories/2', ['auth' => 'none', 'json' => ['category_name' => 'Artists', 'color' => 'rose']])],
    ],
    [
        'id' => 'tag-categories/delete',
        'route' => 'DELETE /tag-categories/{category_id}',
        'about' => 'An empty category: 204. Its Danbooru category mapping (Copyright → Source) is deleted with it. Invalidates tags only.',
        'probe' => true,
        'steps' => [R::delete('/api/tag-categories/4'), R::get('/api/tag-categories'), R::get('/api/danbooru/category-mappings')],
    ],
    [
        'id' => 'tag-categories/delete-in-use',
        'route' => 'DELETE /tag-categories/{category_id}',
        'about' => 'A category with tags: 400 CategoryInUse, with the count.',
        'steps' => [R::delete('/api/tag-categories/2')],
    ],
    [
        'id' => 'tag-categories/delete-not-found',
        'route' => 'DELETE /tag-categories/{category_id}',
        'about' => 'Unknown id: 404 CategoryNotFound. Id 0: 400 InvalidInput.',
        'steps' => [R::delete('/api/tag-categories/999'), R::delete('/api/tag-categories/0')],
    ],
    [
        'id' => 'tag-categories/delete-no-token',
        'route' => 'DELETE /tag-categories/{category_id}',
        'about' => 'Without a token: 401 Unauthorized.',
        'steps' => [R::delete('/api/tag-categories/5', ['auth' => 'none'])],
    ],

    // ── Tag implications ────────────────────────────────────────────────────
    [
        'id' => 'tag-implications/index',
        'route' => 'GET /tag-implications',
        'about' => 'All implications by tag name, then implied name; cached.',
        'steps' => [R::get('/api/tag-implications'), R::get('/api/tag-implications')],
    ],
    [
        'id' => 'tag-implications/create',
        'route' => 'POST /tag-implications',
        'about' => '201 with both names; tagging hero now brings sidekick. Invalidates tags only.',
        'probe' => true,
        'steps' => [
            R::post('/api/tag-implications', ['json' => ['tag_id' => 15, 'implied_tag_id' => 16]]),
            R::patch('/api/media/11/tags', ['auth' => 'none', 'json' => ['tag_ids' => [15]]]),
        ],
    ],
    [
        'id' => 'tag-implications/create-existing',
        'route' => 'POST /tag-implications',
        'about' => 'An implication that already exists: 201 again.',
        'steps' => [R::post('/api/tag-implications', ['json' => ['tag_id' => 3, 'implied_tag_id' => 1]])],
    ],
    [
        'id' => 'tag-implications/create-invalid',
        'route' => 'POST /tag-implications',
        'about' => 'Self: 400 CannotImplySelf. animal → kitten would close kitten → cat → animal: 400 CycleDetected. '
            . 'Unknown tag: 404 TagDoesNotExist. No ids: 400 InvalidTagID.',
        'steps' => [
            R::post('/api/tag-implications', ['json' => ['tag_id' => 3, 'implied_tag_id' => 3]]),
            R::post('/api/tag-implications', ['json' => ['tag_id' => 1, 'implied_tag_id' => 5]]),
            R::post('/api/tag-implications', ['json' => ['tag_id' => 3, 'implied_tag_id' => 999]]),
            R::post('/api/tag-implications', ['json' => (object) []]),
        ],
    ],
    [
        'id' => 'tag-implications/create-no-token',
        'route' => 'POST /tag-implications',
        'about' => 'Without a token: 401 Unauthorized.',
        'steps' => [R::post('/api/tag-implications', ['auth' => 'none', 'json' => ['tag_id' => 15, 'implied_tag_id' => 16]])],
    ],
    [
        'id' => 'tag-implications/delete',
        'route' => 'DELETE /tag-implications/{tag_id}/{implied_tag_id}',
        'about' => '204. Invalidates tags only.',
        'probe' => true,
        'steps' => [R::delete('/api/tag-implications/3/1'), R::get('/api/tag-implications')],
    ],
    [
        'id' => 'tag-implications/delete-absent',
        'route' => 'DELETE /tag-implications/{tag_id}/{implied_tag_id}',
        'about' => 'An implication that does not exist: also 204. Id 0: 400 InvalidTagID.',
        'steps' => [R::delete('/api/tag-implications/8/1'), R::delete('/api/tag-implications/0/1')],
    ],
    [
        'id' => 'tag-implications/delete-no-token',
        'route' => 'DELETE /tag-implications/{tag_id}/{implied_tag_id}',
        'about' => 'Without a token: 401 Unauthorized.',
        'steps' => [R::delete('/api/tag-implications/3/1', ['auth' => 'none'])],
    ],

    // ── Danbooru import rules ───────────────────────────────────────────────
    [
        'id' => 'danbooru/category-mappings',
        'route' => 'GET /danbooru/category-mappings',
        'about' => 'All category mappings, with the gallery category name, by Danbooru category id.',
        'steps' => [R::get('/api/danbooru/category-mappings')],
    ],
    [
        'id' => 'danbooru/category-mapping-create',
        'route' => 'POST /danbooru/category-mappings',
        'about' => 'A new mapping: 201. No cache group is invalidated by any import-rule write.',
        'probe' => true,
        'steps' => [
            R::post('/api/danbooru/category-mappings', ['json' => ['danbooru_category_id' => 5, 'danbooru_category_name' => 'Meta', 'gallery_category_id' => 5]]),
            R::get('/api/danbooru/category-mappings'),
        ],
    ],
    [
        'id' => 'danbooru/category-mapping-replace',
        'route' => 'POST /danbooru/category-mappings',
        'about' => 'POSTing an existing Danbooru category id replaces its mapping: 201.',
        'steps' => [
            R::post('/api/danbooru/category-mappings', ['json' => ['danbooru_category_id' => 0, 'danbooru_category_name' => 'General', 'gallery_category_id' => 5]]),
            R::get('/api/danbooru/category-mappings'),
        ],
    ],
    [
        'id' => 'danbooru/category-mapping-create-invalid',
        'route' => 'POST /danbooru/category-mappings',
        'about' => 'Missing Danbooru id, missing name, missing gallery category: 400 InvalidInput each. Unknown gallery category: 404 CategoryNotFound.',
        'steps' => [
            R::post('/api/danbooru/category-mappings', ['json' => ['danbooru_category_name' => 'Meta', 'gallery_category_id' => 1]]),
            R::post('/api/danbooru/category-mappings', ['json' => ['danbooru_category_id' => 5, 'gallery_category_id' => 1]]),
            R::post('/api/danbooru/category-mappings', ['json' => ['danbooru_category_id' => 5, 'danbooru_category_name' => 'Meta']]),
            R::post('/api/danbooru/category-mappings', ['json' => ['danbooru_category_id' => 5, 'danbooru_category_name' => 'Meta', 'gallery_category_id' => 999]]),
        ],
    ],
    [
        'id' => 'danbooru/category-mapping-create-no-token',
        'route' => 'POST /danbooru/category-mappings',
        'about' => 'Without a token: 401 Unauthorized.',
        'steps' => [R::post('/api/danbooru/category-mappings', ['auth' => 'none', 'json' => ['danbooru_category_id' => 5, 'danbooru_category_name' => 'Meta', 'gallery_category_id' => 5]])],
    ],
    [
        'id' => 'danbooru/category-mapping-delete',
        'route' => 'DELETE /danbooru/category-mappings/{danbooru_category_id}',
        'about' => '204; an id with no mapping is also 204. A negative id: 400 InvalidInput.',
        'steps' => [
            R::delete('/api/danbooru/category-mappings/3'),
            R::delete('/api/danbooru/category-mappings/99'),
            R::delete('/api/danbooru/category-mappings/-1'),
            R::get('/api/danbooru/category-mappings'),
        ],
    ],
    [
        'id' => 'danbooru/category-mapping-delete-no-token',
        'route' => 'DELETE /danbooru/category-mappings/{danbooru_category_id}',
        'about' => 'Without a token: 401 Unauthorized.',
        'steps' => [R::delete('/api/danbooru/category-mappings/3', ['auth' => 'none'])],
    ],
    [
        'id' => 'danbooru/tag-mappings',
        'route' => 'GET /danbooru/tag-mappings',
        'about' => 'All tag-name mappings by Danbooru tag.',
        'steps' => [R::get('/api/danbooru/tag-mappings')],
    ],
    [
        'id' => 'danbooru/tag-mapping-create',
        'route' => 'POST /danbooru/tag-mappings',
        'about' => '201 with the new id.',
        'steps' => [
            R::post('/api/danbooru/tag-mappings', ['json' => ['danbooru_tag' => 'long_hair', 'gallery_tag' => 'long hair']]),
            R::get('/api/danbooru/tag-mappings'),
        ],
    ],
    [
        'id' => 'danbooru/tag-mapping-create-invalid',
        'route' => 'POST /danbooru/tag-mappings',
        'about' => 'An existing Danbooru tag: 400 DuplicateMapping. Missing either name: 400 InvalidInput.',
        'steps' => [
            R::post('/api/danbooru/tag-mappings', ['json' => ['danbooru_tag' => '1girl', 'gallery_tag' => 'girl']]),
            R::post('/api/danbooru/tag-mappings', ['json' => ['gallery_tag' => 'long hair']]),
            R::post('/api/danbooru/tag-mappings', ['json' => ['danbooru_tag' => 'long_hair', 'gallery_tag' => ' ']]),
        ],
    ],
    [
        'id' => 'danbooru/tag-mapping-create-no-token',
        'route' => 'POST /danbooru/tag-mappings',
        'about' => 'Without a token: 401 Unauthorized.',
        'steps' => [R::post('/api/danbooru/tag-mappings', ['auth' => 'none', 'json' => ['danbooru_tag' => 'long_hair', 'gallery_tag' => 'long hair']])],
    ],
    [
        'id' => 'danbooru/tag-mapping-update',
        'route' => 'PUT /danbooru/tag-mappings/{id}',
        'about' => '200 echoing the mapping.',
        'steps' => [
            R::put('/api/danbooru/tag-mappings/2', ['json' => ['danbooru_tag' => 'cat_ears', 'gallery_tag' => 'kitten']]),
            R::get('/api/danbooru/tag-mappings'),
        ],
    ],
    [
        'id' => 'danbooru/tag-mapping-update-absent',
        'route' => 'PUT /danbooru/tag-mappings/{id}',
        'about' => 'An id with no mapping: 200 echoing the request, though nothing was saved.',
        'steps' => [
            R::put('/api/danbooru/tag-mappings/999', ['json' => ['danbooru_tag' => 'new_tag', 'gallery_tag' => 'new tag']]),
            R::get('/api/danbooru/tag-mappings'),
        ],
    ],
    [
        'id' => 'danbooru/tag-mapping-update-invalid',
        'route' => 'PUT /danbooru/tag-mappings/{id}',
        'about' => 'Another mapping\'s Danbooru tag: 400 DuplicateMapping. No fields: 400 InvalidInput. Id 0: 400 InvalidInput.',
        'steps' => [
            R::put('/api/danbooru/tag-mappings/2', ['json' => ['danbooru_tag' => '1girl', 'gallery_tag' => 'girl']]),
            R::put('/api/danbooru/tag-mappings/2', ['json' => (object) []]),
            R::put('/api/danbooru/tag-mappings/0', ['json' => ['danbooru_tag' => 'x', 'gallery_tag' => 'y']]),
        ],
    ],
    [
        'id' => 'danbooru/tag-mapping-update-no-token',
        'route' => 'PUT /danbooru/tag-mappings/{id}',
        'about' => 'Without a token: 401 Unauthorized.',
        'steps' => [R::put('/api/danbooru/tag-mappings/2', ['auth' => 'none', 'json' => ['danbooru_tag' => 'cat_ears', 'gallery_tag' => 'kitten']])],
    ],
    [
        'id' => 'danbooru/tag-mapping-delete',
        'route' => 'DELETE /danbooru/tag-mappings/{id}',
        'about' => '204; an id with no mapping is also 204. Id 0: 400 InvalidInput.',
        'steps' => [
            R::delete('/api/danbooru/tag-mappings/1'),
            R::delete('/api/danbooru/tag-mappings/999'),
            R::delete('/api/danbooru/tag-mappings/0'),
            R::get('/api/danbooru/tag-mappings'),
        ],
    ],
    [
        'id' => 'danbooru/tag-mapping-delete-no-token',
        'route' => 'DELETE /danbooru/tag-mappings/{id}',
        'about' => 'Without a token: 401 Unauthorized.',
        'steps' => [R::delete('/api/danbooru/tag-mappings/1', ['auth' => 'none'])],
    ],

    // ── Duplicates ──────────────────────────────────────────────────────────
    [
        'id' => 'duplicates/report',
        'route' => 'GET /duplicates/report',
        'about' => 'The fixture report: the dismissed pair (12, 13) and a pair with a deleted item (99) are left out; '
            . 'an old three-element match loads with ssim null. Public.',
        'steps' => [R::get('/api/duplicates/report')],
    ],
    [
        'id' => 'duplicates/report-missing',
        'route' => 'GET /duplicates/report',
        'about' => 'No dupes/ directory: 404 NoDupesDirectory.',
        'files' => ['dupes' => null],
        'steps' => [R::get('/api/duplicates/report')],
    ],
    [
        'id' => 'duplicates/report-none',
        'route' => 'GET /duplicates/report',
        'about' => 'A dupes/ directory with no reports: 404 NoReportsFound.',
        'files' => ['dupes/dupes-2026-10-30.json' => null],
        'steps' => [R::get('/api/duplicates/report')],
    ],
    [
        'id' => 'duplicates/report-corrupt',
        'route' => 'GET /duplicates/report',
        'about' => 'The newest report is not valid JSON: 500 InvalidReport.',
        'files' => ['dupes/dupes-2026-11-01.json' => '{"matches": ['],
        'steps' => [R::get('/api/duplicates/report')],
    ],
    [
        'id' => 'duplicates/scan',
        'route' => 'POST /duplicates/scan',
        'about' => 'Default settings (distance ≤ 6, region difference ≤ 36): pair A (a re-save) and pair C (no thumbnails) are reported; '
            . 'pair B (a variant) is rejected by the region check, pair D is dismissed and pair E is 7 bits apart. '
            . 'The report then reads back from the new file. No cache group is invalidated.',
        'probe' => true,
        'steps' => [R::post('/api/duplicates/scan'), R::get('/api/duplicates/report')],
    ],
    [
        'id' => 'duplicates/scan-region-check-off',
        'route' => 'POST /duplicates/scan',
        'about' => 'DUPLICATE_MAX_REGION_DIFF=off: the variant pair B is reported too.',
        'env' => ['DUPLICATE_MAX_REGION_DIFF' => 'off'],
        'steps' => [R::post('/api/duplicates/scan'), R::get('/api/duplicates/report')],
    ],
    [
        'id' => 'duplicates/scan-wider-cutoff',
        'route' => 'POST /duplicates/scan',
        'about' => 'DUPLICATE_MAX_DISTANCE=8: pair E (7 bits apart) is reported too.',
        'env' => ['DUPLICATE_MAX_DISTANCE' => '8'],
        'steps' => [R::post('/api/duplicates/scan'), R::get('/api/duplicates/report')],
    ],
    [
        'id' => 'duplicates/scan-no-token',
        'route' => 'POST /duplicates/scan',
        'about' => 'Without a token: 401 Unauthorized.',
        'steps' => [R::post('/api/duplicates/scan', ['auth' => 'none'])],
    ],
    [
        'id' => 'duplicates/dismiss',
        'route' => 'POST /duplicates/dismissals',
        'about' => '201 with the pair stored smaller id first; the pair drops out of the report. Dismissing again is also 201.',
        'steps' => [
            R::post('/api/duplicates/dismissals', ['json' => ['media_id_1' => 4, 'media_id_2' => 3]]),
            R::get('/api/duplicates/report'),
            R::post('/api/duplicates/dismissals', ['json' => ['media_id_1' => 3, 'media_id_2' => 4]]),
        ],
    ],
    [
        'id' => 'duplicates/dismiss-invalid',
        'route' => 'POST /duplicates/dismissals',
        'about' => 'The same id twice, or no ids: 400 InvalidInput.',
        'steps' => [
            R::post('/api/duplicates/dismissals', ['json' => ['media_id_1' => 3, 'media_id_2' => 3]]),
            R::post('/api/duplicates/dismissals', ['json' => (object) []]),
        ],
    ],
    [
        'id' => 'duplicates/dismiss-unknown-media',
        'route' => 'POST /duplicates/dismissals',
        'about' => 'An id with no media row is not checked: the foreign key throws and Slim\'s error handler answers 500 with its HTML page.',
        'steps' => [R::post('/api/duplicates/dismissals', ['json' => ['media_id_1' => 3, 'media_id_2' => 999]])],
    ],
    [
        'id' => 'duplicates/dismiss-no-token',
        'route' => 'POST /duplicates/dismissals',
        'about' => 'Without a token: 401 Unauthorized.',
        'steps' => [R::post('/api/duplicates/dismissals', ['auth' => 'none', 'json' => ['media_id_1' => 4, 'media_id_2' => 3]])],
    ],

    // ── Cross-cutting: CORS, rate limiting, unmatched routes ────────────────
    [
        'id' => 'cors/read-origins',
        'route' => 'GET /version',
        'about' => 'A read from an allowed origin gets the CORS headers; one from a foreign origin is still answered, without them. '
            . 'Every response carries X-Frame-Options and X-Content-Type-Options.',
        'steps' => [R::get('/api/version', ['origin' => 'dev']), R::get('/api/version', ['origin' => 'foreign'])],
    ],
    [
        'id' => 'cors/preflight',
        'route' => 'OPTIONS /{any}',
        'about' => 'Preflight from an allowed origin: answered by the CORS middleware itself, 200 with an empty body (not 204), '
            . 'before routing, so an unknown path gets the same answer. A foreign origin gets no CORS headers. Preflights are not rate-limited.',
        'steps' => [
            R::options('/api/tags', ['origin' => 'allowed', 'headers' => ['Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => 'authorization,content-type']]),
            R::options('/api/no-such-route', ['origin' => 'allowed', 'headers' => ['Access-Control-Request-Method' => 'DELETE']]),
            R::options('/api/tags', ['origin' => 'foreign', 'headers' => ['Access-Control-Request-Method' => 'POST']]),
        ],
    ],
    [
        'id' => 'rate-limit/remaining',
        'route' => 'GET /version',
        'about' => 'X-RateLimit-Remaining counts down from 119 per request, across all routes.',
        'steps' => [R::get('/api/version'), R::get('/api/tags'), R::get('/api/version')],
    ],
    [
        'id' => 'rate-limit/exceeded',
        'route' => 'GET /version',
        'about' => '120 requests a minute per IP are allowed; the 121st is 429 RateLimitExceeded with retry_after and Retry-After. '
            . 'A preflight is still answered, and a write from a foreign origin is still refused by the origin check, which runs first.',
        'steps' => [
            R::get('/api/version', ['repeat' => 120]),
            R::get('/api/version'),
            R::post('/api/tags', ['json' => ['tag_name' => 'x']]),
            R::options('/api/tags', ['origin' => 'allowed', 'headers' => ['Access-Control-Request-Method' => 'POST']]),
            R::post('/api/tags', ['origin' => 'foreign', 'json' => ['tag_name' => 'x']]),
        ],
    ],
    [
        'id' => 'errors/unmatched-path',
        'route' => 'ANY /{unmatched}',
        'about' => 'An unmatched path is 405 with "Allow: OPTIONS", never 404: the OPTIONS catch-all route /{routes:.+} matches every '
            . 'path, so the router reports a wrong method. The body is Slim\'s, not { error, message }: HTML for a browser\'s '
            . 'Accept: */*, { "message": … } only when JSON is asked for. Routing runs before the group middleware, so no 401.',
        'steps' => [
            R::get('/api/no-such-route'),
            R::get('/api/no-such-route', ['headers' => ['Accept' => 'application/json']]),
            R::post('/api/no-such-route', ['auth' => 'none', 'json' => (object) []]),
            R::get('/api/media/1/no-such-thing'),
        ],
    ],
    [
        'id' => 'errors/method-not-allowed',
        'route' => 'ANY /{unmatched}',
        'about' => 'A known path with the wrong method is Slim\'s 405 with an Allow header, HTML or JSON by Accept, before any auth check.',
        'steps' => [
            R::put('/api/media/1', ['auth' => 'none', 'json' => (object) []]),
            R::put('/api/media/1', ['auth' => 'none', 'json' => (object) [], 'headers' => ['Accept' => 'application/json']]),
            R::patch('/api/version', ['json' => (object) []]),
        ],
    ],
];
