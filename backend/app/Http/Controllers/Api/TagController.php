<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\TagRequest;
use App\Http\Resources\TagListItemResource;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use App\Support\CacheGroup;
use App\Support\ResponseCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OpenApi\Spec as OAS;

/** Tags: listing, CRUD, and moving one tag's media to another. */
final class TagController extends Controller
{
    public function __construct(private readonly ResponseCache $cache) {}

    /**
     * GET /tags — All tags (id, name, category). Cached.
     */
    #[OAS\Operation\Get(
        path: '/tags',
        operationId: 'listTags',
        summary: 'List all tags',
        tags: ['Tags'],
        responses: [
            new OAS\Response(response: 200, description: 'All tags', content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/Tag'))),
        ],
    )]
    public function index(): JsonResponse
    {
        return $this->cache->remember(
            CacheGroup::Tags,
            'all',
            ResponseCache::TTL_MEDIUM,
            static fn() => TagResource::collection(Tag::query()->orderBy('tag_name')->get()),
        );
    }

    /**
     * GET /tags/display — All tags with category and usage/implication counts,
     * for the tags-management page. Cached.
     */
    #[OAS\Operation\Get(
        path: '/tags/display',
        operationId: 'listTagsForDisplay',
        summary: 'List tags with category and usage counts',
        tags: ['Tags'],
        responses: [
            new OAS\Response(response: 200, description: 'Tags with counts', content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/TagListItem'))),
        ],
    )]
    public function display(): JsonResponse
    {
        return $this->cache->remember(CacheGroup::Tags, 'display', ResponseCache::TTL_MEDIUM, static fn() => TagListItemResource::collection(
            Tag::query()
                ->leftJoin('tag_categories', 'tag_categories.category_id', '=', 'tags.category_id')
                ->select(['tags.tag_id', 'tags.tag_name', 'tags.category_id', 'tag_categories.category_name'])
                ->withCount(['media as media_count', 'implies as implication_count'])
                ->orderBy('tag_categories.sort_order')
                ->orderBy('tags.tag_name')
                ->get(),
        ));
    }

    /**
     * GET /tags/{tag_id} — A single tag by ID.
     */
    #[OAS\Operation\Get(
        path: '/tags/{tag_id}',
        operationId: 'getTag',
        summary: 'A single tag',
        tags: ['Tags'],
        parameters: [new OAS\Parameter(name: 'tag_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        responses: [
            new OAS\Response(response: 200, description: 'The tag', content: new OAS\MediaType\Json(ref: '#/components/schemas/Tag')),
            new OAS\Response(response: 404, description: 'TagDoesNotExist', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(string $tagId): TagResource
    {
        return new TagResource($this->find($tagId, 'The requested tag could not be found.'));
    }

    /**
     * POST /tags — Create a tag. Body: { tag_name, category_id? (default 1) }.
     * Returns the created tag.
     */
    #[OAS\Operation\Post(
        path: '/tags',
        operationId: 'createTag',
        summary: 'Create a tag',
        tags: ['Tags'],
        security: [new OAS\Security\Requirement(scheme: 'bearerAuth')],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['tag_name'],
                properties: [
                    new OAS\Property(property: 'tag_name', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'category_id', schema: new OAS\Schema(type: 'integer', default: 1)),
                ],
            ),
        ),
        responses: [
            new OAS\Response(response: 201, description: 'The created tag', content: new OAS\MediaType\Json(ref: '#/components/schemas/Tag')),
            new OAS\Response(response: 400, description: 'InvalidTagName / TagAlreadyExists', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function store(TagRequest $request): TagResource
    {
        $tag = Tag::query()->create($request->tag());

        $this->cache->bump(CacheGroup::Tags);
        Log::info('Tag created', ['tag_id' => $tag->tag_id, 'tag_name' => $tag->tag_name, 'category_id' => $tag->category_id]);

        return new TagResource($tag);
    }

    /**
     * PUT /tags/{tag_id} — Rename and/or recategorize a tag.
     * Body: { tag_name, category_id? (default 1) }. Returns the updated tag.
     */
    #[OAS\Operation\Put(
        path: '/tags/{tag_id}',
        operationId: 'updateTag',
        summary: 'Update a tag',
        tags: ['Tags'],
        security: [new OAS\Security\Requirement(scheme: 'bearerAuth')],
        parameters: [new OAS\Parameter(name: 'tag_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['tag_name'],
                properties: [
                    new OAS\Property(property: 'tag_name', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'category_id', schema: new OAS\Schema(type: 'integer', default: 1)),
                ],
            ),
        ),
        responses: [
            new OAS\Response(response: 200, description: 'The updated tag', content: new OAS\MediaType\Json(ref: '#/components/schemas/Tag')),
            new OAS\Response(response: 404, description: 'TagDoesNotExist', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function update(TagRequest $request, string $tagId): TagResource
    {
        $tag = $this->find($tagId, 'The tag to edit could not be found.');
        $tag->update($request->tag());

        $this->cache->bump(CacheGroup::Tags);
        Log::info('Tag edited', ['tag_id' => $tag->tag_id, 'tag_name' => $tag->tag_name, 'category_id' => $tag->category_id]);

        return new TagResource($tag);
    }

    /**
     * DELETE /tags/{tag_id} — Delete a tag.
     * DELETE /tags/{tag_id}/migrate-to/{target_tag_id} — migrate this tag's media
     * to the target first, then delete this tag.
     */
    #[OAS\Operation\Delete(
        path: '/tags/{tag_id}',
        operationId: 'deleteTag',
        summary: 'Delete a tag',
        tags: ['Tags'],
        security: [new OAS\Security\Requirement(scheme: 'bearerAuth')],
        parameters: [new OAS\Parameter(name: 'tag_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        responses: [
            new OAS\Response(response: 204, description: 'Deleted'),
            new OAS\Response(response: 404, description: 'TagDoesNotExist', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    #[OAS\Operation\Delete(
        path: '/tags/{tag_id}/migrate-to/{target_tag_id}',
        operationId: 'migrateAndDeleteTag',
        summary: 'Migrate a tag\'s media to another tag, then delete it',
        tags: ['Tags'],
        security: [new OAS\Security\Requirement(scheme: 'bearerAuth')],
        parameters: [
            new OAS\Parameter(name: 'tag_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
            new OAS\Parameter(name: 'target_tag_id', in: 'path', required: true, description: 'Migrate media to this tag before deleting.', schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(response: 204, description: 'Migrated and deleted'),
            new OAS\Response(response: 404, description: 'TagDoesNotExist / MigrationTargetDoesNotExist', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(string $tagId, ?string $targetTagId = null): Response
    {
        $tag = $this->find($tagId, 'The tag to delete could not be found.');

        // A target that isn't a positive id (0 included) means a plain delete.
        $targetId = $targetTagId === null ? null : self::positiveId($targetTagId);
        $target = null;
        if ($targetId !== null) {
            if ($targetId === $tag->tag_id) {
                throw new ApiException('CannotMigrateToSelf', 400, 'A tag cannot be migrated to itself.');
            }
            $target = Tag::query()->find($targetId)
                ?? throw new ApiException('MigrationTargetDoesNotExist', 404, 'The migration target tag could not be found.');
        }

        // Its media links and implications go with it (ON DELETE CASCADE).
        DB::transaction(static function () use ($tag, $target): void {
            if ($target !== null) {
                $tag->moveMediaTo($target);
            }
            $tag->delete();
        });

        $this->cache->bump(CacheGroup::Tags, CacheGroup::Media);
        Log::info('Tag deleted', ['tag_id' => $tag->tag_id, 'migrated_to' => $targetId ?? 0]);

        return response()->noContent();
    }

    /**
     * POST /tags/{tag_id}/migrate — Move all media from this tag to the target,
     * then delete this tag. Body: { target_tag_id }.
     */
    #[OAS\Operation\Post(
        path: '/tags/{tag_id}/migrate',
        operationId: 'migrateTag',
        summary: 'Migrate a tag into another',
        tags: ['Tags'],
        security: [new OAS\Security\Requirement(scheme: 'bearerAuth')],
        parameters: [new OAS\Parameter(name: 'tag_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['target_tag_id'],
                properties: [new OAS\Property(property: 'target_tag_id', schema: new OAS\Schema(type: 'integer'))],
            ),
        ),
        responses: [
            new OAS\Response(response: 200, description: 'Migration result', content: new OAS\MediaType\Json(ref: '#/components/schemas/MigrateResult')),
            new OAS\Response(response: 404, description: 'TagDoesNotExist', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function migrate(Request $request, string $tagId): JsonResponse
    {
        // Despite the description above, the source tag is kept: only its
        // media move, as the contract recorded.
        $sourceId = self::positiveId($tagId);
        $targetId = $request->integer('target_tag_id');
        if ($sourceId === null || $targetId <= 0) {
            throw new ApiException('InvalidTagID', 400, 'Both source and target tag IDs must be positive numbers.');
        }
        if ($sourceId === $targetId) {
            throw new ApiException('CannotMigrateToSelf', 400, 'A tag cannot be migrated to itself.');
        }

        $source = Tag::query()->find($sourceId);
        $target = Tag::query()->find($targetId);
        if ($source === null || $target === null) {
            throw new ApiException('TagDoesNotExist', 404, 'The source or target tag could not be found.');
        }

        $source->moveMediaTo($target);

        $this->cache->bump(CacheGroup::Tags, CacheGroup::Media);
        Log::info('Tag migrated', ['source' => $sourceId, 'target' => $targetId]);

        return new JsonResponse(['migrated' => true, 'source_tag_id' => $sourceId, 'target_tag_id' => $targetId]);
    }

    private function find(string $tagId, string $notFound): Tag
    {
        $id = self::positiveId($tagId) ?? throw new ApiException('InvalidTagID', 400, 'The tag ID must be a positive number.');

        return Tag::query()->find($id) ?? throw new ApiException('TagDoesNotExist', 404, $notFound);
    }
}
