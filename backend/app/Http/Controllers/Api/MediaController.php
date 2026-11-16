<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\MediaListRequest;
use App\Http\Resources\MediaResource;
use App\Http\Resources\TagResource;
use App\Models\Media;
use App\Models\Tag;
use App\OpenApi\ProblemJson;
use App\Support\CacheGroup;
use App\Support\ResponseCache;
use App\Support\Viewer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Log;
use OpenApi\Spec as OAS;
use Throwable;

/**
 * Media items: the listing (the whole gallery, a tag search, or the untagged
 * items), single reads, and deletes. Every listing, count and random pick
 * leaves out media carrying a tag the signed-in account blocks
 * (App\Support\Viewer); a single read doesn't, and the app asks before
 * showing it.
 */
final class MediaController extends Controller
{
    /** The most items POST /media/by-ids asks for. */
    private const int MAX_BY_IDS = 200;

    public function __construct(private readonly ResponseCache $cache) {}

    /**
     * GET /media — Media, newest first: the whole gallery, a tag search
     * (?tags=cat,-dog) or the items with no tags (?untagged=true). A numbered
     * page is ?limit&offset, with the listing's size in X-Total-Count; the
     * items after a page are ?cursor= with the nextCursor it ended with, which
     * items added or removed meanwhile don't throw off (infinite scroll).
     */
    #[OAS\Operation\Get(
        path: '/media',
        operationId: 'listMedia',
        summary: 'List media',
        tags: ['Media'],
        parameters: [
            new OAS\Parameter(name: 'tags', in: 'query', required: false, description: "Comma-separated tag names, any case; a leading '-' excludes (cat,-dog). Names that aren't tags are skipped.", schema: new OAS\Schema(type: 'string', maxLength: 2000)),
            new OAS\Parameter(name: 'untagged', in: 'query', required: false, description: 'Only items with no tags. Not with tags.', schema: new OAS\Schema(type: 'boolean')),
            new OAS\Parameter(name: 'limit', in: 'query', required: false, description: 'Items per page.', schema: new OAS\Schema(type: 'integer', default: 40, maximum: 100, minimum: 1)),
            new OAS\Parameter(name: 'offset', in: 'query', required: false, description: 'Items to skip: a numbered page. Not with cursor.', schema: new OAS\Schema(type: 'integer', default: 0, minimum: 0)),
            new OAS\Parameter(name: 'cursor', in: 'query', required: false, description: 'A nextCursor from this listing: the items after it.', schema: new OAS\Schema(type: 'string')),
        ],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'A page of media',
                headers: [new OAS\Header(header: 'X-Total-Count', description: 'How many items the whole listing holds (paging by offset only).', schema: new OAS\Schema(type: 'integer'))],
                content: new OAS\MediaType\Json(ref: '#/components/schemas/MediaPage'),
            ),
            new OAS\Response(response: 422, description: 'InvalidInput, InvalidCursor, NoValidTagsSupplied (none of the names is a tag)', content: new ProblemJson()),
        ],
    )]
    public function index(MediaListRequest $request): JsonResponse
    {
        $hidden = Viewer::hiddenTags($request);
        $query = Media::query()->hidingTags($hidden);
        $search = $request->tags();
        if ($request->untagged()) {
            $query->untagged();
        } elseif ($search !== null) {
            $query->taggedWith(...self::tagSearch($search));
        }
        $limit = $request->limit();

        $cursor = $request->cursor();
        if ($cursor !== null) {
            $page = $query->newestFirst()->cursorPaginate($limit, ['*'], 'cursor', $cursor);

            return new JsonResponse([
                'items' => MediaResource::collection($page->items()),
                'nextCursor' => $page->nextCursor()?->encode(),
            ]);
        }

        // Keyed by the search as written, so one in other letter case is cached separately.
        $offset = $request->offset();
        $key = implode(':', [$request->untagged() ? 'untagged' : 'tags=' . $search, $limit, $offset]) . Viewer::cacheKey($hidden);

        return $this->cache->rememberPage(CacheGroup::Media, $key, ResponseCache::TTL_SHORT, static function () use ($query, $limit, $offset): array {
            $total = $query->count();
            $items = $query->newestFirst()->offset($offset)->limit($limit)->get();
            $last = $items->last();

            return [[
                'items' => MediaResource::collection($items),
                'nextCursor' => $last !== null && $offset + $items->count() < $total
                    ? new Cursor(['file_time' => $last->file_time, 'media_id' => $last->media_id])->encode()
                    : null,
            ], $total];
        });
    }

    /**
     * GET /media/{mediaId} — A single media item by ID.
     */
    #[OAS\Operation\Get(
        path: '/media/{mediaId}',
        operationId: 'getMedia',
        summary: 'A single media item',
        tags: ['Media'],
        parameters: [
            new OAS\Parameter(name: 'mediaId', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
            new OAS\Parameter(name: 'include', in: 'query', required: false, description: '"tags" adds the item\'s tags, in display order, as `tags`.', schema: new OAS\Schema(type: 'string', enum: ['tags'])),
        ],
        responses: [
            new OAS\Response(response: 200, description: 'The media item (with its tags when include=tags)', content: new OAS\MediaType\Json(ref: '#/components/schemas/MediaWithTags')),
            new OAS\Response(response: 404, description: 'MediaNotFound', content: new ProblemJson()),
            new OAS\Response(response: 422, description: 'InvalidInput (include names something else)', content: new ProblemJson()),
        ],
    )]
    public function show(Request $request, Media $media): MediaResource|JsonResponse
    {
        if (!$request->has('include')) {
            return new MediaResource($media);
        }
        if ($request->query('include') !== 'tags') {
            throw new ApiException('InvalidInput', 422, 'include is "tags" or left out.', [], [['message' => 'include is "tags" or left out.', 'location' => 'query.include']]);
        }

        // Built here: a resource with additional fields is wrapped in { data }.
        return new JsonResponse([
            ...new MediaResource($media)->resolve($request),
            'tags' => TagResource::collection($media->sortedTags())->resolve($request),
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
            new OAS\Response(response: 404, description: 'MediaNotFound (no media to pick from)', content: new ProblemJson()),
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
     * POST /media/by-ids — Media items by id, up to 200. Ids with no item are
     * skipped. A public read that is a POST only to carry a long id list.
     */
    #[OAS\Operation\Post(
        path: '/media/by-ids',
        operationId: 'getMediaByIds',
        summary: 'Batch-fetch media by IDs',
        tags: ['Media'],
        requestBody: new OAS\RequestBody(required: true, content: new OAS\MediaType\Json(ref: '#/components/schemas/MediaIdsBody')),
        responses: [
            new OAS\Response(response: 200, description: 'The items found, newest first', content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/Media'))),
            new OAS\Response(response: 422, description: 'InvalidInput (not a list of 1 to 200 ids)', content: new ProblemJson()),
        ],
    )]
    public function byIds(Request $request): AnonymousResourceCollection
    {
        $ids = self::idList($request->input('ids'), 'ids', self::MAX_BY_IDS);

        // In listing order, not the order asked for.
        return MediaResource::collection(Media::query()->whereKey($ids)->hidingTags(Viewer::hiddenTags($request))->newestFirst()->get());
    }

    /**
     * DELETE /media/{mediaId} — Delete a media item (DB row, file, thumbnails).
     */
    #[OAS\Operation\Delete(
        path: '/media/{mediaId}',
        operationId: 'deleteMedia',
        summary: 'Delete a media item',
        tags: ['Media'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [new OAS\Parameter(name: 'mediaId', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        responses: [
            new OAS\Response(response: 204, description: 'Deleted'),
            new OAS\Response(response: 403, description: 'Forbidden (not an admin)', content: new ProblemJson()),
            new OAS\Response(response: 404, description: 'MediaNotFound', content: new ProblemJson()),
            new OAS\Response(response: 500, description: 'DeleteFailed', content: new ProblemJson()),
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
     * POST /media/bulk-delete — Delete media items by id. Body: { mediaIds }.
     * Each is deleted on its own; the answer lists which were and which
     * weren't.
     */
    #[OAS\Operation\Post(
        path: '/media/bulk-delete',
        operationId: 'bulkDeleteMedia',
        summary: 'Bulk-delete media items',
        tags: ['Media'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        requestBody: new OAS\RequestBody(required: true, content: new OAS\MediaType\Json(ref: '#/components/schemas/BulkDeleteBody')),
        responses: [
            new OAS\Response(response: 200, description: 'Deletion outcome', content: new OAS\MediaType\Json(ref: '#/components/schemas/BulkDeleteResult')),
            new OAS\Response(response: 403, description: 'Forbidden (not an admin)', content: new ProblemJson()),
            new OAS\Response(response: 422, description: 'InvalidInput', content: new ProblemJson()),
        ],
    )]
    public function bulkDestroy(Request $request): JsonResponse
    {
        $ids = $request->input('mediaIds');
        if (!is_array($ids) || $ids === []) {
            throw self::invalid('A list of media IDs to delete is required.', 'body.mediaIds');
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

        return new JsonResponse(['deleted' => $deleted, 'failed' => $failed, 'totalDeleted' => count($deleted)]);
    }

    /**
     * A body's list of ids: 1 to $max positive whole numbers (numeric strings
     * count), or a 422 at $field.
     *
     * @return list<int>
     */
    public static function idList(mixed $ids, string $field, int $max): array
    {
        if (!is_array($ids) || $ids === []) {
            throw self::invalid('A non-empty array of media IDs is required.', "body.{$field}");
        }
        if (count($ids) > $max) {
            throw self::invalid("{$field} lists at most {$max} media IDs.", "body.{$field}");
        }
        $valid = array_values(array_filter(array_map(intval(...), $ids), static fn(int $id): bool => $id > 0));

        return $valid !== [] ? $valid : throw self::invalid('No valid media IDs were provided.', "body.{$field}");
    }

    private static function invalid(string $message, string $location): ApiException
    {
        return new ApiException('InvalidInput', 422, $message, [], [['message' => $message, 'location' => $location]]);
    }

    /**
     * A search's tag list ("cat,-dog") as the ids to include and exclude.
     * Names that aren't tags are dropped; only when none are left is it a 422.
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
            $message = 'None of the specified tag names matched existing tags.';

            throw new ApiException('NoValidTagsSupplied', 422, $message, [], [['message' => $message, 'location' => 'query.tags']]);
        }

        return [$includeIds, $excludeIds];
    }
}
