<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\MediaResource;
use App\Http\Resources\TagResource;
use App\Models\Media;
use App\Models\Tag;
use App\Support\CacheGroup;
use App\Support\ResponseCache;
use App\Support\Viewer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Log;
use OpenApi\Spec as OAS;
use Throwable;

/**
 * Media items: the paged listings and tag search, single reads, and deletes.
 * Every listing, count and random pick leaves out media carrying a tag the
 * signed-in account blocks (App\Support\Viewer); a single read doesn't, and
 * the app asks before showing it.
 */
final class MediaController extends Controller
{
    private const int DEFAULT_PER_PAGE = 40;

    private const int MAX_PER_PAGE = 200;

    /** The most items POST /media/by-ids returns. */
    private const int MAX_BY_IDS = 200;

    public function __construct(private readonly ResponseCache $cache) {}

    /**
     * GET /media/page/{page}[/{per_page}] — A page of media items.
     * Returns { items, total_pages, current_page }. per_page is clamped to 1..200.
     */
    #[OAS\Operation\Get(
        path: '/media/page/{page}/{per_page}',
        operationId: 'listMedia',
        summary: 'A page of media',
        tags: ['Media'],
        parameters: [
            new OAS\Parameter(name: 'page', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
            new OAS\Parameter(name: 'per_page', in: 'path', required: true, schema: new OAS\Schema(type: 'integer', maximum: 200, minimum: 1)),
        ],
        responses: [
            new OAS\Response(response: 200, description: 'A page of media', content: new OAS\MediaType\Json(ref: '#/components/schemas/MediaPage')),
            new OAS\Response(response: 400, description: 'InvalidPageNumber', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(Request $request, string $page, ?string $perPage = null): JsonResponse
    {
        $page = self::pageNumber($page);
        $perPage = self::pageSize($perPage);
        $hidden = Viewer::hiddenTags($request);

        // An empty gallery has 0 pages here, where the other listings say 1.
        return $this->cache->remember(CacheGroup::Media, "page:{$page}:{$perPage}" . Viewer::cacheKey($hidden), ResponseCache::TTL_SHORT, static fn(): array => self::mediaPage(Media::query()->hidingTags($hidden), $page, $perPage, atLeastOnePage: false));
    }

    /**
     * GET /media/untagged/{page}[/{per_page}] — A page of media with no tags.
     */
    #[OAS\Operation\Get(
        path: '/media/untagged/{page}/{per_page}',
        operationId: 'listUntaggedMedia',
        summary: 'A page of untagged media',
        tags: ['Media'],
        parameters: [
            new OAS\Parameter(name: 'page', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
            new OAS\Parameter(name: 'per_page', in: 'path', required: true, schema: new OAS\Schema(type: 'integer', maximum: 200, minimum: 1)),
        ],
        responses: [
            new OAS\Response(response: 200, description: 'A page of untagged media', content: new OAS\MediaType\Json(ref: '#/components/schemas/MediaPage')),
            new OAS\Response(response: 400, description: 'InvalidPageNumber', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function untagged(string $page, ?string $perPage = null): JsonResponse
    {
        $page = self::pageNumber($page);
        $perPage = self::pageSize($perPage);

        // Untagged media carries no tag to block.
        return $this->cache->remember(CacheGroup::Media, "untagged:{$page}:{$perPage}", ResponseCache::TTL_SHORT, static fn(): array => self::mediaPage(Media::query()->untagged(), $page, $perPage));
    }

    /**
     * GET /media/with-tags/{tag_list}/{page}[/{per_page}] — A page of media
     * filtered by a comma-separated tag list; a leading '-' excludes a tag
     * (e.g. "cat,-dog"). Returns { items, total_pages, current_page }.
     */
    #[OAS\Operation\Get(
        path: '/media/with-tags/{tag_list}/{page}/{per_page}',
        operationId: 'searchMediaByTags',
        summary: 'A page of media filtered by tags',
        tags: ['Media'],
        parameters: [
            new OAS\Parameter(name: 'tag_list', in: 'path', required: true, description: "Comma-separated tag names; a leading '-' excludes (e.g. cat,-dog).", schema: new OAS\Schema(type: 'string')),
            new OAS\Parameter(name: 'page', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
            new OAS\Parameter(name: 'per_page', in: 'path', required: true, schema: new OAS\Schema(type: 'integer', maximum: 200, minimum: 1)),
        ],
        responses: [
            new OAS\Response(response: 200, description: 'A page of media', content: new OAS\MediaType\Json(ref: '#/components/schemas/MediaPage')),
            new OAS\Response(response: 404, description: 'NoValidTagsSupplied', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function search(Request $request, string $tagList, string $page, ?string $perPage = null): JsonResponse
    {
        // Unlike the other listings, page 0 is answered (as the first page,
        // with current_page 0), not refused.
        $page = self::number($page);
        $perPage = self::pageSize($perPage);

        [$includeIds, $excludeIds] = self::tagSearch($tagList);
        $hidden = Viewer::hiddenTags($request);

        // Keyed by the list as written, so a search in other letter case is
        // cached separately.
        return $this->cache->remember(CacheGroup::Media, "tags:{$tagList}:{$page}:{$perPage}" . Viewer::cacheKey($hidden), ResponseCache::TTL_SHORT, static fn(): array => self::mediaPage(
            Media::query()->taggedWith($includeIds, $excludeIds)->hidingTags($hidden),
            $page,
            $perPage,
        ));
    }

    /**
     * GET /media/{media_id} — A single media item by ID.
     */
    #[OAS\Operation\Get(
        path: '/media/{media_id}',
        operationId: 'getMedia',
        summary: 'A single media item',
        tags: ['Media'],
        parameters: [
            new OAS\Parameter(name: 'media_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
            new OAS\Parameter(name: 'include', in: 'query', required: false, description: '"tags" adds the item\'s tags, in display order, as `tags`.', schema: new OAS\Schema(type: 'string', enum: ['tags'])),
        ],
        responses: [
            new OAS\Response(response: 200, description: 'The media item (with its tags when include=tags)', content: new OAS\MediaType\Json(ref: '#/components/schemas/MediaWithTags')),
            new OAS\Response(response: 404, description: 'MediaNotFound', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(Request $request, Media $media): MediaResource|JsonResponse
    {
        if ($request->query('include') !== 'tags') {
            return new MediaResource($media);
        }

        // Built here: a resource with additional fields is wrapped in { data }.
        return new JsonResponse([
            ...new MediaResource($media)->resolve($request),
            'tags' => TagResource::collection($media->sortedTags())->resolve($request),
        ]);
    }

    /**
     * GET /media?cursor=…&per_page=…&tags=…|untagged=1 — The items after a
     * cursor (from a page's or a feed's next_cursor), newest first, in the
     * whole gallery, a tag search (as /media/with-tags) or the untagged items.
     * Unlike page numbers, a cursor isn't thrown off by items added or removed
     * meanwhile, which is what infinite scroll needs.
     */
    #[OAS\Operation\Get(
        path: '/media',
        operationId: 'listMediaAfterCursor',
        summary: 'Media after a cursor',
        tags: ['Media'],
        parameters: [
            new OAS\Parameter(name: 'cursor', in: 'query', required: false, description: 'A next_cursor; without one, the listing\'s start.', schema: new OAS\Schema(type: 'string')),
            new OAS\Parameter(name: 'per_page', in: 'query', required: false, schema: new OAS\Schema(type: 'integer', default: 40, maximum: 200, minimum: 1)),
            new OAS\Parameter(name: 'tags', in: 'query', required: false, description: "Comma-separated tag names; a leading '-' excludes (as /media/with-tags).", schema: new OAS\Schema(type: 'string')),
            new OAS\Parameter(name: 'untagged', in: 'query', required: false, description: 'Only items with no tags.', schema: new OAS\Schema(type: 'boolean')),
        ],
        responses: [
            new OAS\Response(response: 200, description: 'The next items', content: new OAS\MediaType\Json(ref: '#/components/schemas/MediaFeed')),
            new OAS\Response(response: 400, description: 'InvalidCursor', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 404, description: 'NoValidTagsSupplied', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function feed(Request $request): JsonResponse
    {
        $cursor = null;
        if ($request->filled('cursor')) {
            $cursor = Cursor::fromEncoded($request->string('cursor')->toString())
                ?? throw new ApiException('InvalidCursor', 400, 'The cursor is not one this listing gave out.');
        }
        $perPage = self::pageSize($request->filled('per_page') ? $request->string('per_page')->toString() : null);

        $query = Media::query()->hidingTags(Viewer::hiddenTags($request));
        if ($request->boolean('untagged')) {
            $query->untagged();
        } elseif ($request->filled('tags')) {
            $query->taggedWith(...self::tagSearch($request->string('tags')->toString()));
        }
        $page = $query->newestFirst()->cursorPaginate($perPage, ['*'], 'cursor', $cursor);

        return new JsonResponse([
            'items' => MediaResource::collection($page->items()),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    /**
     * GET /media/random — A single random media item.
     */
    #[OAS\Operation\Get(
        path: '/media/random',
        operationId: 'getRandomMedia',
        summary: 'A random media item',
        tags: ['Media'],
        responses: [
            new OAS\Response(response: 200, description: 'A random media item', content: new OAS\MediaType\Json(ref: '#/components/schemas/Media')),
            new OAS\Response(response: 404, description: 'MediaNotFound', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function random(Request $request): MediaResource
    {
        return new MediaResource(Media::pickRandom(Viewer::hiddenTags($request)) ?? throw new ApiException('MediaNotFound', 404, 'No media items are available.'));
    }

    /**
     * GET /media/count — The total media count, as { count }.
     */
    #[OAS\Operation\Get(
        path: '/media/count',
        operationId: 'countMedia',
        summary: 'Total media count',
        tags: ['Media'],
        responses: [
            new OAS\Response(response: 200, description: 'The count', content: new OAS\MediaType\Json(ref: '#/components/schemas/Count')),
        ],
    )]
    public function count(Request $request): JsonResponse
    {
        $hidden = Viewer::hiddenTags($request);

        return $this->cache->remember(CacheGroup::Media, 'count' . Viewer::cacheKey($hidden), ResponseCache::TTL_MEDIUM, static fn(): array => ['count' => Media::query()->hidingTags($hidden)->count()]);
    }

    /**
     * POST /media/by-ids — Get multiple media items by an array of IDs.
     * Body: { ids: int[] } (max 200). Missing IDs are silently skipped.
     * A public batched read (uses POST only to carry a large id list).
     */
    #[OAS\Operation\Post(
        path: '/media/by-ids',
        operationId: 'getMediaByIds',
        summary: 'Batch-fetch media by IDs',
        tags: ['Media'],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['ids'],
                properties: [new OAS\Property(property: 'ids', schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(type: 'integer')))],
            ),
        ),
        responses: [
            new OAS\Response(response: 200, description: 'Matching media items', content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/Media'))),
            new OAS\Response(response: 400, description: 'InvalidInput', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function byIds(Request $request): AnonymousResourceCollection
    {
        $ids = $request->input('ids');
        if (!is_array($ids) || $ids === []) {
            throw new ApiException('InvalidInput', 400, 'A non-empty array of media IDs is required.');
        }

        // Numeric strings count; anything else reads as 0 and is skipped.
        $ids = array_slice(array_values(array_filter(array_map(intval(...), $ids), static fn(int $id): bool => $id > 0)), 0, self::MAX_BY_IDS);
        if ($ids === []) {
            throw new ApiException('InvalidInput', 400, 'No valid media IDs were provided.');
        }

        // In listing order, not the order asked for.
        return MediaResource::collection(Media::query()->whereKey($ids)->hidingTags(Viewer::hiddenTags($request))->newestFirst()->get());
    }

    /**
     * DELETE /media/{media_id} — Delete a media item (DB row, file, thumbnails).
     */
    #[OAS\Operation\Delete(
        path: '/media/{media_id}',
        operationId: 'deleteMedia',
        summary: 'Delete a media item',
        tags: ['Media'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [new OAS\Parameter(name: 'media_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        responses: [
            new OAS\Response(response: 204, description: 'Deleted'),
            new OAS\Response(response: 404, description: 'MediaNotFound', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(Media $media): Response
    {
        try {
            $media->delete();
        } catch (Throwable $e) {
            Log::error('Failed to delete media', ['media_id' => $media->media_id, 'error' => $e->getMessage()]);

            throw new ApiException('DeleteFailed', 500, 'The media item could not be deleted. Please try again.');
        }

        Log::info('Media deleted', ['media_id' => $media->media_id]);

        return response()->noContent();
    }

    /**
     * POST /media/bulk-delete — Delete one or more media items by ID.
     * Body: { media_ids: int[] }. Returns the deleted and failed ID lists.
     */
    #[OAS\Operation\Post(
        path: '/media/bulk-delete',
        operationId: 'bulkDeleteMedia',
        summary: 'Bulk-delete media items',
        tags: ['Media'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['media_ids'],
                properties: [new OAS\Property(property: 'media_ids', schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(type: 'integer')))],
            ),
        ),
        responses: [
            new OAS\Response(response: 200, description: 'Deletion outcome', content: new OAS\MediaType\Json(ref: '#/components/schemas/BulkDeleteResult')),
            new OAS\Response(response: 400, description: 'InvalidInput', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function bulkDestroy(Request $request): JsonResponse
    {
        $ids = $request->input('media_ids');
        if (!is_array($ids) || $ids === []) {
            throw new ApiException('InvalidInput', 400, 'A list of media IDs to delete is required.');
        }
        Log::info('Bulk media delete requested', ['count' => count($ids)]);

        // Each id is deleted on its own; one that can't be (unknown, not a
        // positive number, or failing) is listed as failed, as a number.
        $deleted = [];
        $failed = [];
        foreach (array_map(intval(...), $ids) as $id) {
            try {
                $media = $id > 0 ? Media::query()->find($id) : null;
                if ($media?->delete() === true) {
                    $deleted[] = $id;
                } else {
                    $failed[] = $id;
                }
            } catch (Throwable) {
                $failed[] = $id;
            }
        }


        return new JsonResponse(['deleted' => $deleted, 'failed' => $failed, 'total_deleted' => count($deleted)]);
    }

    /**
     * One page of the query's media, newest first (the MediaPage schema), with
     * the cursor to continue from with GET /media while more follow.
     *
     * @param Builder<Media> $query
     * @return array{items: AnonymousResourceCollection, total_pages: int, current_page: int, next_cursor: ?string}
     */
    private static function mediaPage(Builder $query, int $page, int $perPage, bool $atLeastOnePage = true): array
    {
        $pages = (int) ceil($query->count() / $perPage);
        $items = $query->newestFirst()->forPage($page, $perPage)->get();
        $last = $items->last();

        return [
            'items' => MediaResource::collection($items),
            'total_pages' => $atLeastOnePage ? max(1, $pages) : $pages,
            'current_page' => $page,
            'next_cursor' => $last !== null && $items->count() === $perPage && max($page, 1) < $pages
                ? new Cursor(['file_time' => $last->file_time, 'media_id' => $last->media_id])->encode()
                : null,
        ];
    }

    /**
     * A search's tag list ("cat,-dog") as the ids to include and exclude.
     * Names that aren't tags are dropped; only when none are left is it a 404.
     *
     * @return array{0: list<int>, 1: list<int>}
     */
    private static function tagSearch(string $tagList): array
    {
        $include = [];
        $exclude = [];
        foreach (array_map(trim(...), explode(',', $tagList)) as $name) {
            if (str_starts_with($name, '-')) {
                $exclude[] = ltrim(substr($name, 1));
            } else {
                $include[] = $name;
            }
        }
        $includeIds = Tag::idsNamed(array_values(array_filter($include, static fn(string $name): bool => $name !== '')));
        $excludeIds = Tag::idsNamed(array_values(array_filter($exclude, static fn(string $name): bool => $name !== '')));
        if ($includeIds === [] && $excludeIds === []) {
            throw new ApiException('NoValidTagsSupplied', 404, 'None of the specified tag names matched existing tags.');
        }

        return [$includeIds, $excludeIds];
    }

    /** A path segment as a number: one that isn't reads as 0. */
    private static function number(string $segment): int
    {
        return ctype_digit($segment) ? (int) $segment : 0;
    }

    private static function pageNumber(string $segment): int
    {
        $page = self::number($segment);

        return $page >= 1 ? $page : throw new ApiException('InvalidPageNumber', 400, 'Page number must be 1 or greater.');
    }

    /** Items per page: 40 when left out, otherwise kept to 1–200. */
    private static function pageSize(?string $segment): int
    {
        return $segment === null ? self::DEFAULT_PER_PAGE : min(max(self::number($segment), 1), self::MAX_PER_PAGE);
    }

}
