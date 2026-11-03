<?php

declare(strict_types=1);

namespace Tests\Support\Contract;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\ContractFixture;

/**
 * The Danbooru API the recordings were made against
 * (backend-old/tests/Fixtures/danbooru-stub.php), as an Http::fake(). It
 * answers the same three calls the same way, refuses other credentials with
 * 401, and logs each call as the stub did, without credentials, so a step can
 * be checked for the calls it made.
 *
 * Down, every call fails to connect, as when the recorder stopped the stub.
 */
final class DanbooruStub
{
    private const string LOGIN = 'contract';

    private const string API_KEY = 'contract-key';

    /** @var list<string> */
    private array $calls = [];

    private function __construct() {}

    public static function install(bool $up): self
    {
        $stub = new self();
        Http::preventStrayRequests();
        Http::fake(['danbooru.donmai.us/*' => $up ? $stub->answer(...) : Http::failedConnection()]);

        return $stub;
    }

    /**
     * The calls made since the last take, as "GET /path?query".
     *
     * @return list<string>
     */
    public function takeCalls(): array
    {
        [$calls, $this->calls] = [$this->calls, []];

        return $calls;
    }

    private function answer(Request $request): PromiseInterface
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $logged = array_diff_key($query, ['login' => true, 'api_key' => true]);
        $this->calls[] = 'GET ' . $path . ($logged === [] ? '' : '?' . urldecode(http_build_query($logged)));

        if (($query['login'] ?? null) !== self::LOGIN || ($query['api_key'] ?? null) !== self::API_KEY) {
            return Http::response(['success' => false, 'message' => 'Authentication failed'], 401);
        }

        if ($path === '/posts.json') {
            $tags = is_string($query['tags'] ?? null) ? $query['tags'] : '';

            return match (str_starts_with($tags, 'md5:') ? substr($tags, 4) : '') {
                md5('contract-media-1') => Http::response([self::post(1001)]),
                self::tinyPngMd5() => Http::response([self::post(1003)]),
                md5('contract-media-15') => Http::response(['success' => false, 'message' => 'Authentication failed'], 401),
                md5('contract-media-16') => Http::response(['success' => false, 'message' => 'Too many requests'], 429),
                md5('contract-media-18') => Http::response(['success' => false, 'message' => 'Internal error'], 500),
                default => Http::response([]),
            };
        }

        if (preg_match('#^/posts/(\d+)\.json$#', $path, $m) === 1) {
            $post = self::post((int) $m[1]);

            return $post === null
                ? Http::response(['success' => false, 'error' => 'ActiveRecord::RecordNotFound'], 404)
                : Http::response($post);
        }

        if ($path === '/iqdb_queries.json') {
            $search = $query['search'] ?? null;
            $url = is_array($search) && is_string($search['url'] ?? null) ? $search['url'] : '';

            return str_ends_with($url, '/media/full/dog-01.webp')
                ? Http::response([['post_id' => 1002, 'score' => 95.5, 'post' => ['id' => 1002, 'md5' => '0123456789abcdef0123456789abcdef']]])
                : Http::response([]);
        }

        return Http::response(['success' => false, 'error' => 'Not found'], 404);
    }

    /** @return array<string, int|string>|null */
    private static function post(int $id): ?array
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
                'md5' => self::tinyPngMd5(),
                'tag_string_general' => '1girl smiling',
                'tag_string_artist' => '',
                'tag_string_character' => '',
                'tag_string_copyright' => 'original',
                'tag_string_meta' => '',
            ],
        ];

        return isset($posts[$id]) ? ['id' => $id] + $posts[$id] : null;
    }

    private static function tinyPngMd5(): string
    {
        return (string) md5_file(ContractFixture::path('uploads/tiny.png'));
    }
}
