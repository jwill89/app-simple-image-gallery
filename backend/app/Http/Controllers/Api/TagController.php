<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\TagListRequest;
use App\Http\Requests\TagRequest;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use App\OpenApi\ProblemJson;
use App\Support\CacheGroup;
use App\Support\ResponseCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OpenApi\Spec as OAS;

/**
 * Tags: finding them, CRUD, and moving one tag's media to another. Writes
 * clear the cached lists through the Tag model.
 */
final class TagController extends Controller
{
    public function __construct(private readonly ResponseCache $cache) {}

    /**
     * GET /tags — A page of tags, by name unless ?sort says otherwise, with
     * how many match in X-Total-Count. ?search= ranks the matches for a name
     * (exact, then prefix, then containing), for pickers; ?filter= keeps tags
     * whose own or category's name contains it; ?ids= and ?names= look tags
     * up; ?exclude= leaves ids out. With ?include=counts each tag carries its
     * media and implication counts. Cached.
     */
    #[OAS\Operation\Get(
        path: '/tags',
        operationId: 'listTags',
        summary: 'List, find or look up tags',
        tags: ['Tags'],
        parameters: [
            new OAS\Parameter(name: 'search', in: 'query', required: false, description: 'A name to rank matches for: exact, then starting with it, then containing it. Empty ranks every tag by name. Not with filter or sort.', schema: new OAS\Schema(type: 'string', maxLength: 200)),
            new OAS\Parameter(name: 'filter', in: 'query', required: false, description: 'Keeps tags whose name, or whose category\'s name, contains this (any case).', schema: new OAS\Schema(type: 'string', maxLength: 200)),
            new OAS\Parameter(name: 'ids', in: 'query', required: false, description: 'Comma-separated tag ids to look up (at most 100).', schema: new OAS\Schema(type: 'string')),
            new OAS\Parameter(name: 'names', in: 'query', required: false, description: 'Comma-separated tag names (any case) to look up (at most 100).', schema: new OAS\Schema(type: 'string')),
            new OAS\Parameter(name: 'exclude', in: 'query', required: false, description: 'Comma-separated tag ids to leave out.', schema: new OAS\Schema(type: 'string')),
            new OAS\Parameter(name: 'sort', in: 'query', required: false, schema: new OAS\Schema(type: 'string', default: 'tagName', enum: ['tagName', 'categoryName', 'mediaCount'])),
            new OAS\Parameter(name: 'direction', in: 'query', required: false, schema: new OAS\Schema(type: 'string', default: 'asc', enum: ['asc', 'desc'])),
            new OAS\Parameter(name: 'limit', in: 'query', required: false, schema: new OAS\Schema(type: 'integer', default: 100, maximum: 100, minimum: 1)),
            new OAS\Parameter(name: 'offset', in: 'query', required: false, schema: new OAS\Schema(type: 'integer', default: 0, minimum: 0)),
            new OAS\Parameter(name: 'include', in: 'query', required: false, description: '"counts" adds each tag\'s mediaCount and implicationCount.', schema: new OAS\Schema(type: 'string', enum: ['counts'])),
        ],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'A page of tags',
                headers: [new OAS\Header(header: 'X-Total-Count', description: 'How many tags match, on every page.', schema: new OAS\Schema(type: 'integer'))],
                content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/Tag')),
            ),
            new OAS\Response(response: 422, description: 'InvalidInput', content: new ProblemJson()),
        ],
    )]
    public function index(TagListRequest $request): JsonResponse
    {
        $key = json_encode([
            $request->ids(), $request->names(), $request->search(), $request->filter(), $request->exclude(),
            $request->sort(), $request->direction(), $request->limit(), $request->offset(), $request->withCounts(),
        ], JSON_THROW_ON_ERROR);

        return $this->cache->rememberPage(CacheGroup::Tags, "list:{$key}", ResponseCache::TTL_MEDIUM, static function () use ($request): array {
            $query = Tag::query()->select(['tags.tag_id', 'tags.tag_name', 'tags.category_id']);
            $ids = $request->ids();
            if ($ids !== null) {
                $query->whereIn('tags.tag_id', $ids);
            }
            $names = $request->names();
            if ($names !== null) {
                $query->whereIn('tags.tag_name', $names);
            }
            $exclude = $request->exclude();
            if ($exclude !== []) {
                $query->whereNotIn('tags.tag_id', $exclude);
            }
            $filter = $request->filter();
            if ($filter !== '' || $request->sort() === TagListRequest::SORTS['categoryName']) {
                $query->leftJoin('tag_categories', 'tag_categories.category_id', '=', 'tags.category_id');
            }
            if ($filter !== '') {
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filter) . '%';
                $query->where(static fn($where) => $where
                    ->whereRaw("tags.tag_name LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("tag_categories.category_name LIKE ? ESCAPE '\\'", [$like]));
            }

            $search = $request->search();
            if ($search !== null) {
                $query->matching($search);
            }
            $total = (clone $query)->count();

            if ($search === null) {
                $sort = $request->sort();
                $query->orderBy($sort === 'media_count'
                    ? DB::table('media_tags')->selectRaw('COUNT(*)')->whereColumn('media_tags.tag_id', 'tags.tag_id')
                    : $sort, $request->direction())->orderBy('tags.tag_name');
            }
            if ($request->withCounts()) {
                $query->withCount(['media as media_count', 'implies as implication_count']);
            }

            return [TagResource::collection($query->offset($request->offset())->limit($request->limit())->get()), $total];
        });
    }

    /**
     * GET /tags/{tagId} — A single tag by ID.
     */
    #[OAS\Operation\Get(
        path: '/tags/{tagId}',
        operationId: 'getTag',
        summary: 'A single tag',
        tags: ['Tags'],
        parameters: [new OAS\Parameter(name: 'tagId', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        responses: [
            new OAS\Response(response: 200, description: 'The tag', content: new OAS\MediaType\Json(ref: '#/components/schemas/Tag')),
            new OAS\Response(response: 404, description: 'TagNotFound', content: new ProblemJson()),
        ],
    )]
    public function show(Tag $tag): TagResource
    {
        return new TagResource($tag);
    }

    /**
     * POST /tags — Create a tag. Body: { tagName, categoryId? (default 1) }.
     */
    #[OAS\Operation\Post(
        path: '/tags',
        operationId: 'createTag',
        summary: 'Create a tag',
        tags: ['Tags'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        requestBody: new OAS\RequestBody(required: true, content: new OAS\MediaType\Json(ref: '#/components/schemas/TagCreateBody')),
        responses: [
            new OAS\Response(response: 201, description: 'The created tag', content: new OAS\MediaType\Json(ref: '#/components/schemas/Tag')),
            new OAS\Response(response: 403, description: 'Forbidden (not an admin)', content: new ProblemJson()),
            new OAS\Response(response: 409, description: 'TagAlreadyExists', content: new ProblemJson()),
            new OAS\Response(response: 422, description: 'InvalidTagName, TagNameTooLong, InvalidCategoryID', content: new ProblemJson()),
        ],
    )]
    public function store(TagRequest $request): TagResource
    {
        $tag = Tag::query()->create($request->tag());

        Log::info('Tag created', ['tag_id' => $tag->tag_id, 'tag_name' => $tag->tag_name, 'category_id' => $tag->category_id]);

        return new TagResource($tag);
    }

    /**
     * PATCH /tags/{tagId} — Rename and/or recategorize a tag. Body:
     * { tagName?, categoryId? }; a field left out stays as it is.
     */
    #[OAS\Operation\Patch(
        path: '/tags/{tagId}',
        operationId: 'updateTag',
        summary: 'Update a tag (the fields given)',
        tags: ['Tags'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [new OAS\Parameter(name: 'tagId', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        requestBody: new OAS\RequestBody(required: true, content: new OAS\MediaType\Json(ref: '#/components/schemas/TagUpdateBody')),
        responses: [
            new OAS\Response(response: 200, description: 'The updated tag', content: new OAS\MediaType\Json(ref: '#/components/schemas/Tag')),
            new OAS\Response(response: 403, description: 'Forbidden (not an admin)', content: new ProblemJson()),
            new OAS\Response(response: 404, description: 'TagNotFound', content: new ProblemJson()),
            new OAS\Response(response: 409, description: 'TagAlreadyExists', content: new ProblemJson()),
            new OAS\Response(response: 422, description: 'InvalidTagName, TagNameTooLong, InvalidCategoryID', content: new ProblemJson()),
        ],
    )]
    public function update(TagRequest $request, Tag $tag): TagResource
    {
        $tag->update($request->tag());

        Log::info('Tag edited', ['tag_id' => $tag->tag_id, 'tag_name' => $tag->tag_name, 'category_id' => $tag->category_id]);

        return new TagResource($tag);
    }

    /**
     * DELETE /tags/{tagId} — Delete a tag.
     * DELETE /tags/{tagId}/migrate-to/{targetTagId} — move this tag's media
     * to the target first, then delete this tag.
     */
    #[OAS\Operation\Delete(
        path: '/tags/{tagId}',
        operationId: 'deleteTag',
        summary: 'Delete a tag',
        tags: ['Tags'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [new OAS\Parameter(name: 'tagId', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        responses: [
            new OAS\Response(response: 204, description: 'Deleted'),
            new OAS\Response(response: 403, description: 'Forbidden (not an admin)', content: new ProblemJson()),
            new OAS\Response(response: 404, description: 'TagNotFound', content: new ProblemJson()),
        ],
    )]
    #[OAS\Operation\Delete(
        path: '/tags/{tagId}/migrate-to/{targetTagId}',
        operationId: 'migrateAndDeleteTag',
        summary: 'Move a tag\'s media to another tag, then delete it',
        tags: ['Tags'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'tagId', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
            new OAS\Parameter(name: 'targetTagId', in: 'path', required: true, description: 'Move media to this tag before deleting.', schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(response: 204, description: 'Moved and deleted'),
            new OAS\Response(response: 403, description: 'Forbidden (not an admin)', content: new ProblemJson()),
            new OAS\Response(response: 404, description: 'TagNotFound', content: new ProblemJson()),
            new OAS\Response(response: 422, description: 'CannotMigrateToSelf', content: new ProblemJson()),
        ],
    )]
    public function destroy(Tag $tag, ?Tag $target = null): Response
    {
        if ($target?->tag_id === $tag->tag_id) {
            throw new ApiException('CannotMigrateToSelf', 422, 'A tag cannot be migrated to itself.');
        }

        // Its media links and implications go with it (ON DELETE CASCADE).
        DB::transaction(static function () use ($tag, $target): void {
            if ($target !== null) {
                $tag->moveMediaTo($target);
            }
            $tag->delete();
        });

        Log::info('Tag deleted', ['tag_id' => $tag->tag_id, 'migrated_to' => $target->tag_id ?? 0]);

        return response()->noContent();
    }

    /**
     * POST /tags/{tagId}/migrate — Move all this tag's media to the target,
     * keeping this tag (now on no media). Body: { targetTagId }. To move and
     * delete in one step, DELETE /tags/{tagId}/migrate-to/{targetTagId}.
     */
    #[OAS\Operation\Post(
        path: '/tags/{tagId}/migrate',
        operationId: 'migrateTag',
        summary: 'Move a tag\'s media to another tag, keeping it',
        tags: ['Tags'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [new OAS\Parameter(name: 'tagId', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        requestBody: new OAS\RequestBody(required: true, content: new OAS\MediaType\Json(ref: '#/components/schemas/TagMigrateBody')),
        responses: [
            new OAS\Response(response: 200, description: 'Migration result', content: new OAS\MediaType\Json(ref: '#/components/schemas/MigrateResult')),
            new OAS\Response(response: 403, description: 'Forbidden (not an admin)', content: new ProblemJson()),
            new OAS\Response(response: 404, description: 'TagNotFound', content: new ProblemJson()),
            new OAS\Response(response: 422, description: 'InvalidTagID, CannotMigrateToSelf, TagNotFound (the target)', content: new ProblemJson()),
        ],
    )]
    public function migrate(Request $request, Tag $tag): JsonResponse
    {
        $targetId = $request->integer('targetTagId');
        if ($targetId <= 0) {
            throw self::invalid('InvalidTagID', 'The target tag ID must be a positive number.');
        }
        if ($targetId === $tag->tag_id) {
            throw self::invalid('CannotMigrateToSelf', 'A tag cannot be migrated to itself.');
        }
        $target = Tag::query()->find($targetId) ?? throw self::invalid('TagNotFound', 'The target tag could not be found.');

        $tag->moveMediaTo($target);

        Log::info('Tag migrated', ['source' => $tag->tag_id, 'target' => $target->tag_id]);

        return new JsonResponse(['migrated' => true, 'sourceTagId' => $tag->tag_id, 'targetTagId' => $target->tag_id]);
    }

    private static function invalid(string $code, string $message): ApiException
    {
        return new ApiException($code, 422, $message, [], [['message' => $message, 'location' => 'body.targetTagId']]);
    }
}
