<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\TagResource;
use App\Models\Media;
use App\Models\Tag;
use App\Services\Danbooru\DanbooruTagger;
use App\Support\CacheGroup;
use App\Support\ResponseCache;
use App\Support\TagImports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use App\OpenApi\ProblemJson;
use OpenApi\Spec as OAS;

/**
 * A media item's tags. Anyone may tag (adding and removing are public); the
 * Danbooru import needs an admin account. Tagging clears the cached lists
 * through the Media model.
 */
final class MediaTagController extends Controller
{
    /** The most items one GET /media/tag-imports asks about. */
    private const int MAX_IMPORT_IDS = 200;

    public function __construct(private readonly ResponseCache $cache) {}

    /**
     * GET /media/{mediaId}/tags — Tags applied to a media item. Cached.
     */
    #[OAS\Operation\Get(
        path: '/media/{mediaId}/tags',
        operationId: 'listMediaTags',
        summary: "A media item's tags",
        tags: ['Media'],
        parameters: [new OAS\Parameter(name: 'mediaId', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        responses: [
            new OAS\Response(response: 200, description: 'The tags', content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/Tag'))),
            new OAS\Response(response: 404, description: 'MediaNotFound', content: new ProblemJson()),
        ],
    )]
    public function index(Media $media): JsonResponse
    {
        return $this->cache->remember(CacheGroup::Tags, "for:media:{$media->media_id}", ResponseCache::TTL_SHORT, static fn() => TagResource::collection($media->sortedTags()));
    }

    /**
     * PATCH /media/{mediaId}/tags — Apply tags to a media item (implications
     * resolved). Body: { tagIds: int[] }. Returns the item's updated tag list.
     * Public (anyone may tag).
     */
    #[OAS\Operation\Patch(
        path: '/media/{mediaId}/tags',
        operationId: 'addMediaTags',
        summary: 'Add tags to a media item',
        tags: ['Media'],
        parameters: [new OAS\Parameter(name: 'mediaId', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        requestBody: new OAS\RequestBody(required: true, content: new OAS\MediaType\Json(ref: '#/components/schemas/MediaTagsBody')),
        responses: [
            new OAS\Response(response: 200, description: 'Updated tag list', content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/Tag'))),
            new OAS\Response(response: 404, description: 'MediaNotFound', content: new ProblemJson()),
            new OAS\Response(response: 422, description: 'InvalidTagList, TagNotFound (an id with no tag)', content: new ProblemJson()),
        ],
    )]
    public function store(Request $request, Media $media): AnonymousResourceCollection
    {
        // A single id is taken as a list of one; ids that aren't positive numbers are dropped.
        $tagIds = array_values(array_unique(array_filter(
            array_map(intval(...), (array) $request->input('tagIds', [])),
            static fn(int $id): bool => $id > 0,
        )));
        if ($tagIds === []) {
            throw self::invalid('InvalidTagList', 'At least one valid tag must be provided.');
        }

        // Every tag must exist, or none is applied.
        $missing = array_values(array_diff($tagIds, Tag::query()->whereKey($tagIds)->pluck('tag_id')->all()));
        if ($missing !== []) {
            throw self::invalid('TagNotFound', "Tag #{$missing[0]} does not exist.");
        }

        $media->addTags($tagIds);

        Log::info('Tags added to media', ['media_id' => $media->media_id, 'tag_ids' => $tagIds]);

        return TagResource::collection($media->sortedTags());
    }

    /**
     * DELETE /media/{mediaId}/tags/{tagId} — Remove a tag from a media item.
     * Returns the item's updated tag list. Public (anyone may tag).
     */
    #[OAS\Operation\Delete(
        path: '/media/{mediaId}/tags/{tagId}',
        operationId: 'removeMediaTag',
        summary: 'Remove a tag from a media item',
        tags: ['Media'],
        parameters: [
            new OAS\Parameter(name: 'mediaId', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
            new OAS\Parameter(name: 'tagId', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(response: 200, description: 'Updated tag list', content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/Tag'))),
            new OAS\Response(response: 404, description: 'MediaNotFound, TagNotFound', content: new ProblemJson()),
        ],
    )]
    public function destroy(Media $media, Tag $tag): AnonymousResourceCollection
    {
        // Only this tag: what it implies stays. One the item doesn't have is not an error.
        $media->removeTag($tag);

        Log::info('Tag removed from media', ['media_id' => $media->media_id, 'tag_id' => $tag->tag_id]);

        return TagResource::collection($media->sortedTags());
    }

    /**
     * POST /media/{mediaId}/danbooru-tags — Import tags from Danbooru for a
     * media item. Body: { danbooruPostId? }. With a post ID, imports directly;
     * otherwise auto-looks-up by MD5 hash then IQDB visual similarity.
     */
    #[OAS\Operation\Post(
        path: '/media/{mediaId}/danbooru-tags',
        operationId: 'importDanbooruTags',
        summary: 'Import Danbooru tags for a media item',
        tags: ['Media'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [new OAS\Parameter(name: 'mediaId', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        requestBody: new OAS\RequestBody(required: false, content: new OAS\MediaType\Json(ref: '#/components/schemas/DanbooruImportBody')),
        responses: [
            new OAS\Response(response: 200, description: 'Import result', content: new OAS\MediaType\Json(ref: '#/components/schemas/DanbooruFetchResult')),
            new OAS\Response(response: 403, description: 'Forbidden (not an admin)', content: new ProblemJson()),
            new OAS\Response(response: 404, description: 'MediaNotFound, NotFoundOnDanbooru', content: new ProblemJson()),
            new OAS\Response(response: 429, description: 'DanbooruRateLimited', content: new ProblemJson()),
            new OAS\Response(response: 500, description: 'DanbooruNotConfigured, DanbooruAuthFailed', content: new ProblemJson()),
            new OAS\Response(response: 503, description: 'DanbooruUnreachable, DanbooruUnavailable, DanbooruBlocked', content: new ProblemJson()),
        ],
    )]
    public function importFromDanbooru(Request $request, DanbooruTagger $tagger, Media $media): JsonResponse
    {
        if (!DanbooruTagger::isConfigured()) {
            throw new ApiException('DanbooruNotConfigured', 500, 'Danbooru credentials are not configured on the server.');
        }

        $postId = $request->integer('danbooruPostId');
        $import = $postId > 0 ? $tagger->importFromPost($media, $postId) : $tagger->importForMedia($media);

        if (!$import->found) {
            if ($import->failure !== null) {
                Log::warning('Danbooru lookup failed', ['media_id' => $media->media_id, 'reason' => $import->failure->name, 'ray' => $import->ray]);

                throw $import->failure->exception($import->ray);
            }

            throw new ApiException('NotFoundOnDanbooru', 404, $postId > 0
                ? "Danbooru post #{$postId} could not be found."
                : 'This media could not be found on Danbooru by hash or visual similarity.');
        }

        Log::info('Danbooru tags imported', [
            'media_id' => $media->media_id,
            'method' => $import->method,
            'tagsApplied' => $import->tagsApplied,
            'tagsCreated' => $import->tagsCreated,
        ]);

        return new JsonResponse([
            'tags' => TagResource::collection($media->sortedTags()),
            'method' => $import->method,
            'tagsApplied' => $import->tagsApplied,
            'tagsCreated' => $import->tagsCreated,
        ]);
    }

    /**
     * GET /media/tag-imports?ids=1,2,3 — Where each item's queued Danbooru
     * import stands (an upload's, or the commands'), for up to 200 items.
     */
    #[OAS\Operation\Get(
        path: '/media/tag-imports',
        operationId: 'listTagImportStatuses',
        summary: "Where media items' queued Danbooru imports stand",
        tags: ['Media'],
        parameters: [new OAS\Parameter(name: 'ids', in: 'query', required: true, description: 'Comma-separated media ids (up to 200).', schema: new OAS\Schema(type: 'string'))],
        responses: [
            new OAS\Response(response: 200, description: 'Each item\'s import, in the order asked', content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/TagImportStatus'))),
            new OAS\Response(response: 422, description: 'InvalidInput (not 1 to 200 ids)', content: new ProblemJson()),
        ],
    )]
    public function importStatus(Request $request, TagImports $imports): JsonResponse
    {
        $ids = array_values(array_unique(array_filter(
            array_map(intval(...), explode(',', $request->string('ids')->toString())),
            static fn(int $id): bool => $id > 0,
        )));
        if ($ids === [] || count($ids) > self::MAX_IMPORT_IDS) {
            $message = 'ids is a comma-separated list of 1 to ' . self::MAX_IMPORT_IDS . ' media ids.';

            throw new ApiException('InvalidInput', 422, $message, [], [['message' => $message, 'location' => 'query.ids']]);
        }

        return new JsonResponse($imports->statuses($ids));
    }

    private static function invalid(string $code, string $message): ApiException
    {
        return new ApiException($code, 422, $message, [], [['message' => $message, 'location' => 'body.tagIds']]);
    }
}
