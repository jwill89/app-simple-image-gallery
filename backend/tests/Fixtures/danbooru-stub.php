<?php

declare(strict_types=1);

/**
 * A stand-in for the three Danbooru API calls DanbooruTagger makes, served with
 * `php -S` by bin/record-contract.php so the import endpoints can be recorded
 * without reaching the real service. The recorder points its staged copy of
 * DanbooruTagger at this server.
 *
 * Answers by the MD5 in `posts.json?tags=md5:…` (see the notes in contract.sql):
 *   media 1, uploads/tiny.png   → a post (1001, 1003)
 *   media 15 / 16 / 18          → HTTP 401 / 429 / 500
 *   anything else               → no posts
 * IQDB matches only media 6's file (post 1002, score 95.5). Each call is
 * appended to calls.log in the server's document root, without credentials,
 * so a recording can show which calls an endpoint made.
 */

const LOGIN = 'contract';
const API_KEY = 'contract-key';

/** @return array<string, int|string>|null */
function post(int $id): ?array
{
    $posts = [
        1001 => [
            'md5' => md5('contract-media-1'),
            'tag_string_general' => 'cat_ears blue_sky new_thing',
            'tag_string_artist' => 'artist_one',
            'tag_string_character' => 'brand_new_hero',
            'tag_string_copyright' => 'some_series',
            'tag_string_meta' => 'highres',
        ],
        1002 => [
            'md5' => '0123456789abcdef0123456789abcdef',
            'tag_string_general' => 'dog smiling',
            'tag_string_artist' => 'artist_two',
            'tag_string_character' => '',
            'tag_string_copyright' => '',
            'tag_string_meta' => '',
        ],
        1003 => [
            'md5' => (string) md5_file(__DIR__ . '/uploads/tiny.png'),
            'tag_string_general' => '1girl smiling',
            'tag_string_artist' => '',
            'tag_string_character' => '',
            'tag_string_copyright' => 'original',
            'tag_string_meta' => '',
        ],
    ];

    return isset($posts[$id]) ? ['id' => $id] + $posts[$id] : null;
}

function respond(int $status, mixed $body): bool
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_THROW_ON_ERROR);

    return true;
}

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$query = $_GET;
unset($query['login'], $query['api_key']);
$docRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? __DIR__);
file_put_contents($docRoot . '/calls.log', 'GET ' . $path . ($query === [] ? '' : '?' . urldecode(http_build_query($query))) . "\n", FILE_APPEND);

if (($_GET['login'] ?? null) !== LOGIN || ($_GET['api_key'] ?? null) !== API_KEY) {
    return respond(401, ['success' => false, 'message' => 'Authentication failed']);
}

if ($path === '/posts.json') {
    $tags = is_string($_GET['tags'] ?? null) ? $_GET['tags'] : '';
    $md5 = str_starts_with($tags, 'md5:') ? substr($tags, 4) : '';

    return match ($md5) {
        md5('contract-media-1') => respond(200, [post(1001)]),
        (string) md5_file(__DIR__ . '/uploads/tiny.png') => respond(200, [post(1003)]),
        md5('contract-media-15') => respond(401, ['success' => false, 'message' => 'Authentication failed']),
        md5('contract-media-16') => respond(429, ['success' => false, 'message' => 'Too many requests']),
        md5('contract-media-18') => respond(500, ['success' => false, 'message' => 'Internal error']),
        default => respond(200, []),
    };
}

if (preg_match('#^/posts/(\d+)\.json$#', $path, $m) === 1) {
    $post = post((int) $m[1]);

    return $post === null
        ? respond(404, ['success' => false, 'error' => 'ActiveRecord::RecordNotFound'])
        : respond(200, $post);
}

if ($path === '/iqdb_queries.json') {
    $search = $_GET['search'] ?? null;
    $url = is_array($search) && is_string($search['url'] ?? null) ? $search['url'] : '';

    return str_ends_with($url, '/media/full/dog-01.webp')
        ? respond(200, [['post_id' => 1002, 'score' => 95.5, 'post' => ['id' => 1002, 'md5' => '0123456789abcdef0123456789abcdef']]])
        : respond(200, []);
}

return respond(404, ['success' => false, 'error' => 'Not found']);
