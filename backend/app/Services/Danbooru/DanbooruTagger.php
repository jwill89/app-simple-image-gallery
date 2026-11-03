<?php

declare(strict_types=1);

namespace App\Services\Danbooru;

use App\Models\DanbooruCategoryMapping;
use App\Models\DanbooruTagMapping;
use App\Models\Media;
use App\Models\Tag;
use App\Models\TagCategory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Finds a media item's post on Danbooru and gives the item the post's tags,
 * as the import rules map them: only Danbooru categories with a category
 * mapping are read, tag names with a tag mapping are renamed, and names that
 * aren't gallery tags yet are created. What the tags imply is not applied.
 *
 * A post is found by the file's MD5 first, then, for an image, by IQDB visual
 * similarity, which catches re-encoded copies. IQDB fetches the image from
 * the gallery's public URL (gallery.url).
 */
final class DanbooruTagger
{
    private const string API_BASE = 'https://danbooru.donmai.us';

    /** The lowest IQDB similarity score (0–100) taken as a match. */
    private const int IQDB_SCORE_THRESHOLD = 90;

    /** Why the last API call failed, or null when it succeeded or simply missed. */
    private ?DanbooruFailure $lastFailure = null;

    /** Cloudflare's Ray ID for the last call, when the failure was a block. */
    private ?string $lastRay = null;

    public static function isConfigured(): bool
    {
        return config()->string('gallery.danbooru.login') !== '' && config()->string('gallery.danbooru.api_key') !== '';
    }

    /** Look the item up by its MD5, then IQDB, and apply the post's tags. */
    public function importForMedia(Media $media): DanbooruImport
    {
        $this->lastFailure = null;
        $this->lastRay = null;

        $posts = $this->apiGet('/posts.json', ['tags' => 'md5:' . $media->hash]);
        $post = is_array($posts[0] ?? null) ? $posts[0] : null;
        $method = 'md5';

        if ($post === null) {
            $post = $this->iqdbLookup($media->file_name);
            $method = 'iqdb';
        }
        if ($post === null) {
            return new DanbooruImport(found: false, failure: $this->lastFailure, ray: $this->lastRay);
        }

        return $this->applyPostTags($media, $post, $method);
    }

    /** Apply the tags of a post given by its id. */
    public function importFromPost(Media $media, int $postId): DanbooruImport
    {
        $this->lastFailure = null;
        $this->lastRay = null;

        $post = $this->apiGet("/posts/{$postId}.json");
        if ($post === null || !is_int($post['id'] ?? null)) {
            return new DanbooruImport(found: false, failure: $this->lastFailure, ray: $this->lastRay);
        }

        return $this->applyPostTags($media, $post, 'post_id');
    }

    /**
     * Give the item the post's tags, all of them or (on failure) none.
     *
     * @param array<mixed> $post
     */
    private function applyPostTags(Media $media, array $post, string $method): DanbooruImport
    {
        // Danbooru tag => gallery category, for the categories with a mapping.
        // Each category's tags are in the post's tag_string_<category name>.
        $wanted = [];
        foreach (DanbooruCategoryMapping::query()->orderBy('danbooru_category_id')->get() as $mapping) {
            $tagString = $post['tag_string_' . strtolower($mapping->danbooru_category_name)] ?? null;
            foreach (is_string($tagString) ? explode(' ', $tagString) : [] as $danbooruTag) {
                if ($danbooruTag !== '') {
                    $wanted[$danbooruTag] = $mapping->gallery_category_id;
                }
            }
        }

        $renames = DanbooruTagMapping::query()->pluck('gallery_tag', 'danbooru_tag')->all();
        $names = [];
        foreach (array_keys($wanted) as $danbooruTag) {
            $names[$danbooruTag] = (string) ($renames[$danbooruTag] ?? str_replace('_', ' ', (string) $danbooruTag));
        }

        return DB::transaction(static function () use ($media, $wanted, $names, $method): DanbooruImport {
            $categories = TagCategory::query()->pluck('category_id')->all();
            // Tag names match without regard to (ASCII) case, as the column does.
            $tagIds = [];
            foreach (Tag::query()->whereIn('tag_name', array_values($names))->get(['tag_id', 'tag_name']) as $tag) {
                $tagIds[strtolower($tag->tag_name)] = $tag->tag_id;
            }

            $applied = [];
            $created = 0;
            foreach ($wanted as $danbooruTag => $categoryId) {
                if (!in_array($categoryId, $categories, true)) {
                    continue;
                }
                $name = $names[$danbooruTag];
                $key = strtolower($name);
                if (!isset($tagIds[$key])) {
                    $tagIds[$key] = Tag::query()->create(['tag_name' => $name, 'category_id' => $categoryId])->tag_id;
                    $created++;
                }
                $applied[] = $tagIds[$key];
            }
            $media->tags()->syncWithoutDetaching($applied);

            return new DanbooruImport(found: true, method: $method, tagsApplied: count($applied), tagsCreated: $created);
        });
    }

    /**
     * The best IQDB match's full post, when it scores at least the threshold.
     * Only for images, and only with a public gallery URL to give IQDB. IQDB's
     * own failures count as a miss.
     *
     * @return array<mixed>|null
     */
    private function iqdbLookup(string $fileName): ?array
    {
        $galleryUrl = config()->string('gallery.url');
        if ($galleryUrl === '' || !in_array(strtolower(pathinfo($fileName, PATHINFO_EXTENSION)), Media::IMAGE_EXTENSIONS, true)) {
            return null;
        }

        try {
            $response = $this->request(60)->get('/iqdb_queries.json', [
                ...$this->credentials(),
                'search' => ['url' => $galleryUrl . '/media/full/' . rawurlencode($fileName)],
            ]);
        } catch (ConnectionException $e) {
            Log::debug('Danbooru IQDB lookup failed', ['error' => $e->getMessage()]);

            return null;
        }

        // Results come best first.
        $best = $response->status() === 200 ? $response->json('0') : null;
        $score = is_array($best) && is_numeric($best['score'] ?? null) ? (float) $best['score'] : 0.0;
        $postId = is_array($best) && is_array($best['post'] ?? null) && is_int($best['post']['id'] ?? null) ? $best['post']['id'] : 0;
        if ($score < self::IQDB_SCORE_THRESHOLD || $postId === 0) {
            return null;
        }

        // IQDB's post is a summary: the tag strings are on the full post.
        $post = $this->apiGet("/posts/{$postId}.json");

        return is_array($post) ? $post : null;
    }

    /**
     * An authenticated GET, decoded. Null when it didn't answer 200, with the
     * reason in $lastFailure unless it was a plain miss (a 404 and the like).
     *
     * @param array<string, string> $query
     * @return array<mixed>|null
     */
    private function apiGet(string $path, array $query = []): ?array
    {
        try {
            $response = $this->request(30)->get($path, [...$query, ...$this->credentials()]);
        } catch (ConnectionException $e) {
            Log::debug('Danbooru request failed', ['path' => $path, 'error' => $e->getMessage()]);
            $this->lastFailure = DanbooruFailure::Network;

            return null;
        }

        if ($response->status() === 200) {
            $this->lastFailure = null;
            $this->lastRay = null;
            $data = $response->json();

            return is_array($data) ? $data : null;
        }

        $this->lastFailure = self::failureFor($response);
        $this->lastRay = $this->lastFailure === DanbooruFailure::Blocked && $response->header('CF-Ray') !== ''
            ? $response->header('CF-Ray')
            : null;
        Log::debug('Danbooru request refused', ['path' => $path, 'status' => $response->status()]);

        return null;
    }

    private static function failureFor(Response $response): ?DanbooruFailure
    {
        return match (true) {
            // The API refuses in JSON; an HTML 403 is the firewall's block page.
            $response->status() === 403 && !is_array($response->json()) => DanbooruFailure::Blocked,
            in_array($response->status(), [401, 403], true) => DanbooruFailure::Auth,
            $response->status() === 429 => DanbooruFailure::RateLimit,
            $response->serverError() => DanbooruFailure::Upstream,
            default => null,
        };
    }

    /**
     * Who is asking, as Danbooru asks API clients to say: the app, and the
     * account's user ID when it is set ("GalleryTagImporter/1.0; user #123").
     * Its firewall blocks requests that don't.
     */
    public static function userAgent(): string
    {
        $userId = config()->string('gallery.danbooru.user_id');

        return 'GalleryTagImporter/1.0' . ($userId !== '' ? "; user #{$userId}" : '');
    }

    private function request(int $timeout): PendingRequest
    {
        return Http::baseUrl(self::API_BASE)->withUserAgent(self::userAgent())->acceptJson()->timeout($timeout);
    }

    /** @return array{login: string, api_key: string} */
    private function credentials(): array
    {
        return ['login' => config()->string('gallery.danbooru.login'), 'api_key' => config()->string('gallery.danbooru.api_key')];
    }
}
