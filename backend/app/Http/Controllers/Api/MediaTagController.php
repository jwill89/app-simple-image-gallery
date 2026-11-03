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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use OpenApi\Spec as OAS;

/**
 * A media item's tags. Anyone may tag (adding and removing are public); the
 * Danbooru import needs the admin's token.
 */
final class MediaTagController extends Controller
{
    public function __construct(private readonly ResponseCache $cache) {}

    /**
     * GET /media/{media_id}/tags — Tags applied to a media item. Cached.
     */
    #[OAS\Operation\Get(
        path: '/media/{media_id}/tags',
        operationId: 'listMediaTags',
        summary: "A media item's tags",
        tags: ['Media'],
        parameters: [new OAS\Parameter(name: 'media_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        responses: [
            new OAS\Response(response: 200, description: 'The tags', content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/Tag'))),
            new OAS\Response(response: 404, description: 'MediaDoesNotExist', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(string $mediaId): JsonResponse
    {
        $media = $this->find($mediaId);

        return $this->cache->remember(CacheGroup::Tags, "for:media:{$media->media_id}", ResponseCache::TTL_SHORT, static fn() => TagResource::collection($media->sortedTags()));
    }

    /**
     * PATCH /media/{media_id}/tags — Apply tags to a media item (implications
     * resolved). Body: { tag_ids: int[] }. Returns the item's updated tag list.
     * Public (anyone may tag).
     */
    #[OAS\Operation\Patch(
        path: '/media/{media_id}/tags',
        operationId: 'addMediaTags',
        summary: 'Add tags to a media item',
        tags: ['Media'],
        parameters: [new OAS\Parameter(name: 'media_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['tag_ids'],
                properties: [new OAS\Property(property: 'tag_ids', schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(type: 'integer')))],
            ),
        ),
        responses: [
            new OAS\Response(response: 200, description: 'Updated tag list', content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/Tag'))),
            new OAS\Response(response: 404, description: 'MediaDoesNotExist / TagDoesNotExist', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function store(Request $request, string $mediaId): AnonymousResourceCollection
    {
        $media = $this->find($mediaId);

        // A single id is taken as a list of one; ids that aren't positive numbers are dropped.
        $tagIds = array_values(array_unique(array_filter(
            array_map(intval(...), (array) $request->input('tag_ids', [])),
            static fn(int $id): bool => $id > 0,
        )));
        if ($tagIds === []) {
            throw new ApiException('InvalidTagList', 400, 'At least one valid tag must be provided.');
        }

        // Every tag must exist, or none is applied.
        $missing = array_values(array_diff($tagIds, Tag::query()->whereKey($tagIds)->pluck('tag_id')->all()));
        if ($missing !== []) {
            throw new ApiException('TagDoesNotExist', 404, "Tag #{$missing[0]} does not exist.");
        }

        $media->addTags($tagIds);

        $this->cache->bump(CacheGroup::Media, CacheGroup::Tags);
        Log::info('Tags added to media', ['media_id' => $media->media_id, 'tag_ids' => $tagIds]);

        return TagResource::collection($media->sortedTags());
    }

    /**
     * DELETE /media/{media_id}/tags/{tag_id} — Remove a tag from a media item.
     * Returns the item's updated tag list. Public (anyone may tag).
     */
    #[OAS\Operation\Delete(
        path: '/media/{media_id}/tags/{tag_id}',
        operationId: 'removeMediaTag',
        summary: 'Remove a tag from a media item',
        tags: ['Media'],
        parameters: [
            new OAS\Parameter(name: 'media_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
            new OAS\Parameter(name: 'tag_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(response: 200, description: 'Updated tag list', content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/Tag'))),
            new OAS\Response(response: 404, description: 'MediaDoesNotExist / CouldNotFindTag', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(string $mediaId, string $tagId): AnonymousResourceCollection
    {
        $media = $this->find($mediaId);
        $id = self::positiveId($tagId) ?? throw new ApiException('InvalidTagID', 400, 'The tag ID must be a positive number.');
        $tag = Tag::query()->find($id) ?? throw new ApiException('CouldNotFindTag', 404, 'The tag to remove could not be found.');

        // Only this tag: what it implies stays. One the item doesn't have is not an error.
        $media->tags()->detach($tag->tag_id);

        $this->cache->bump(CacheGroup::Media, CacheGroup::Tags);
        Log::info('Tag removed from media', ['media_id' => $media->media_id, 'tag_id' => $tag->tag_id]);

        return TagResource::collection($media->sortedTags());
    }

    /**
     * POST /media/{media_id}/danbooru-tags — Import tags from Danbooru for a
     * media item. Body: { danbooru_post_id? }. With a post ID, imports directly;
     * otherwise auto-looks-up by MD5 hash then IQDB visual similarity.
     */
    #[OAS\Operation\Post(
        path: '/media/{media_id}/danbooru-tags',
        operationId: 'importDanbooruTags',
        summary: 'Import Danbooru tags for a media item',
        tags: ['Media'],
        security: [new OAS\Security\Requirement(scheme: 'bearerAuth')],
        parameters: [new OAS\Parameter(name: 'media_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        requestBody: new OAS\RequestBody(
            required: false,
            content: new OAS\MediaType\Json(properties: [new OAS\Property(property: 'danbooru_post_id', schema: new OAS\Schema(type: 'integer', nullable: true))]),
        ),
        responses: [
            new OAS\Response(response: 200, description: 'Import result', content: new OAS\MediaType\Json(ref: '#/components/schemas/DanbooruFetchResult')),
            new OAS\Response(response: 404, description: 'MediaDoesNotExist / NotFoundOnDanbooru', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 429, description: 'DanbooruRateLimited', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 500, description: 'DanbooruNotConfigured / DanbooruAuthFailed', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 503, description: 'DanbooruUnreachable / DanbooruUnavailable', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function importFromDanbooru(Request $request, DanbooruTagger $tagger, string $mediaId): JsonResponse
    {
        $id = self::positiveId($mediaId) ?? throw new ApiException('InvalidMediaID', 400, 'A valid media ID is required.');
        if (!DanbooruTagger::isConfigured()) {
            throw new ApiException('DanbooruNotConfigured', 500, 'Danbooru credentials are not configured on the server.');
        }
        $media = self::existing($id);

        $postId = $request->integer('danbooru_post_id');
        $import = $postId > 0 ? $tagger->importFromPost($media, $postId) : $tagger->importForMedia($media);

        if (!$import->found) {
            if ($import->failure !== null) {
                Log::warning('Danbooru lookup failed', ['media_id' => $media->media_id, 'reason' => $import->failure->name]);

                throw $import->failure->exception();
            }

            throw new ApiException('NotFoundOnDanbooru', 404, $postId > 0
                ? "Danbooru post #{$postId} could not be found."
                : 'This media could not be found on Danbooru by hash or visual similarity.');
        }

        $this->cache->bump(CacheGroup::Media, CacheGroup::Tags);
        Log::info('Danbooru tags imported', [
            'media_id' => $media->media_id,
            'method' => $import->method,
            'tags_applied' => $import->tagsApplied,
            'tags_created' => $import->tagsCreated,
        ]);

        return new JsonResponse([
            'tags' => TagResource::collection($media->sortedTags()),
            'all_tags' => TagResource::collection(Tag::query()->orderBy('tag_name')->get()),
            'method' => $import->method,
            'tags_applied' => $import->tagsApplied,
            'tags_created' => $import->tagsCreated,
        ]);
    }

    private function find(string $mediaId): Media
    {
        return self::existing(self::positiveId($mediaId) ?? throw new ApiException('InvalidMediaID', 400, 'The media ID must be a positive number.'));
    }

    private static function existing(int $mediaId): Media
    {
        return Media::query()->find($mediaId) ?? throw new ApiException('MediaDoesNotExist', 404, 'The media item could not be found.');
    }
}
