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
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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
    /** Search results when no limit is asked for. */
    private const int DEFAULT_LIMIT = 20;

    private const int MAX_LIMIT = 100;

    /** The most tags ?ids= or ?names= return. */
    private const int MAX_LOOKUP = 200;

    private const int DEFAULT_DISPLAY_PER_PAGE = 25;

    private const array DISPLAY_SORTS = ['tag_name', 'category_name', 'media_count'];

    public function __construct(private readonly ResponseCache $cache) {}

    /**
     * GET /tags — Tags (id, name, category). With ?search= the best matches
     * for a name (exact, then prefix, then containing; ?limit= of them, 20 by
     * default, ?exclude= ids left out); with ?names= or ?ids= those tags;
     * with none, every tag (cached).
     */
    #[OAS\Operation\Get(
        path: '/tags',
        operationId: 'listTags',
        summary: 'Find tags, or list them all',
        tags: ['Tags'],
        parameters: [
            new OAS\Parameter(name: 'search', in: 'query', required: false, description: 'Name to match; empty lists the first tags by name.', schema: new OAS\Schema(type: 'string')),
            new OAS\Parameter(name: 'limit', in: 'query', required: false, description: 'How many matches (1-100, default 20).', schema: new OAS\Schema(type: 'integer', maximum: 100, minimum: 1)),
            new OAS\Parameter(name: 'exclude', in: 'query', required: false, description: 'Comma-separated tag ids to leave out of a search.', schema: new OAS\Schema(type: 'string')),
            new OAS\Parameter(name: 'names', in: 'query', required: false, description: 'Comma-separated tag names (any case) to look up.', schema: new OAS\Schema(type: 'string')),
            new OAS\Parameter(name: 'ids', in: 'query', required: false, description: 'Comma-separated tag ids to look up.', schema: new OAS\Schema(type: 'string')),
        ],
        responses: [
            new OAS\Response(response: 200, description: 'The tags', content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/Tag'))),
        ],
    )]
    public function index(Request $request): JsonResponse|AnonymousResourceCollection
    {
        if ($request->has('search')) {
            $exclude = self::ids($request->string('exclude')->toString());

            return TagResource::collection(Tag::query()
                ->matching(Tag::normalizeName($request->string('search')->toString()))
                ->when($exclude !== [], static fn($query) => $query->whereKeyNot($exclude))
                ->limit(min(max($request->integer('limit', self::DEFAULT_LIMIT), 1), self::MAX_LIMIT))
                ->get());
        }
        if ($request->has('names')) {
            $names = array_map(Tag::normalizeName(...), explode(',', $request->string('names')->toString()));

            return TagResource::collection(Tag::query()->whereIn('tag_name', array_slice(array_filter($names, static fn(string $name): bool => $name !== ''), 0, self::MAX_LOOKUP))->orderBy('tag_name')->get());
        }
        if ($request->has('ids')) {
            return TagResource::collection(Tag::query()->whereKey(array_slice(self::ids($request->string('ids')->toString()), 0, self::MAX_LOOKUP))->orderBy('tag_name')->get());
        }

        return $this->cache->remember(
            CacheGroup::Tags,
            'all',
            ResponseCache::TTL_MEDIUM,
            static fn() => TagResource::collection(Tag::query()->orderBy('tag_name')->get()),
        );
    }

    /**
     * GET /tags/display — A page of tags with their category and usage and
     * implication counts, for the tag-management pages. ?search= matches tag
     * or category names; ?sort= tag_name, category_name or media_count (the
     * default), ?direction= asc or desc; ?ids= picks tags. Cached.
     */
    #[OAS\Operation\Get(
        path: '/tags/display',
        operationId: 'listTagsForDisplay',
        summary: 'A page of tags with category and usage counts',
        tags: ['Tags'],
        parameters: [
            new OAS\Parameter(name: 'page', in: 'query', required: false, schema: new OAS\Schema(type: 'integer', default: 1, minimum: 1)),
            new OAS\Parameter(name: 'per_page', in: 'query', required: false, schema: new OAS\Schema(type: 'integer', default: 25, maximum: 100, minimum: 1)),
            new OAS\Parameter(name: 'search', in: 'query', required: false, description: 'Matches tag or category names.', schema: new OAS\Schema(type: 'string')),
            new OAS\Parameter(name: 'sort', in: 'query', required: false, schema: new OAS\Schema(type: 'string', default: 'media_count', enum: ['tag_name', 'category_name', 'media_count'])),
            new OAS\Parameter(name: 'direction', in: 'query', required: false, schema: new OAS\Schema(type: 'string', default: 'desc', enum: ['asc', 'desc'])),
            new OAS\Parameter(name: 'ids', in: 'query', required: false, description: 'Comma-separated tag ids to show.', schema: new OAS\Schema(type: 'string')),
        ],
        responses: [
            new OAS\Response(response: 200, description: 'A page of tags with counts', content: new OAS\MediaType\Json(ref: '#/components/schemas/TagListPage')),
        ],
    )]
    public function display(Request $request): JsonResponse
    {
        $page = max($request->integer('page', 1), 1);
        $perPage = min(max($request->integer('per_page', self::DEFAULT_DISPLAY_PER_PAGE), 1), self::MAX_LIMIT);
        $search = mb_strtolower(trim($request->string('search')->toString()), 'UTF-8');
        $sort = in_array($request->query('sort'), self::DISPLAY_SORTS, true) ? $request->string('sort')->toString() : 'media_count';
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';
        $ids = $request->has('ids') ? array_slice(self::ids($request->string('ids')->toString()), 0, self::MAX_LOOKUP) : null;

        $key = 'display:' . md5((string) json_encode([$page, $perPage, $search, $sort, $direction, $ids]));

        return $this->cache->remember(CacheGroup::Tags, $key, ResponseCache::TTL_MEDIUM, static function () use ($page, $perPage, $search, $sort, $direction, $ids): array {
            $query = Tag::query()->leftJoin('tag_categories', 'tag_categories.category_id', '=', 'tags.category_id');
            if ($search !== '') {
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
                $query->where(static fn($where) => $where
                    ->whereRaw("tags.tag_name LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("tag_categories.category_name LIKE ? ESCAPE '\\'", [$like]));
            }
            if ($ids !== null) {
                $query->whereIn('tags.tag_id', $ids);
            }
            $total = (clone $query)->count();

            $tags = $query
                ->select(['tags.tag_id', 'tags.tag_name', 'tags.category_id', 'tag_categories.category_name'])
                ->withCount(['media as media_count', 'implies as implication_count'])
                ->orderBy($sort === 'category_name' ? 'tag_categories.category_name' : $sort, $direction)
                ->orderBy('tags.tag_name')
                ->forPage($page, $perPage)
                ->get();

            return [
                'items' => TagListItemResource::collection($tags),
                'total' => $total,
                'current_page' => $page,
                'total_pages' => max(1, (int) ceil($total / $perPage)),
            ];
        });
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
            new OAS\Response(response: 404, description: 'TagNotFound', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(Tag $tag): TagResource
    {
        return new TagResource($tag);
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
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
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
            new OAS\Response(response: 400, description: 'InvalidTagName / TagNameTooLong / TagAlreadyExists / InvalidCategoryID', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function store(TagRequest $request): TagResource
    {
        $tag = Tag::query()->create($request->tag());

        Log::info('Tag created', ['tag_id' => $tag->tag_id, 'tag_name' => $tag->tag_name, 'category_id' => $tag->category_id]);

        return new TagResource($tag);
    }

    /**
     * PUT|PATCH /tags/{tag_id} — Rename and/or recategorize a tag. Body:
     * { tag_name?, category_id? }; a field left out stays as it is. Returns
     * the updated tag.
     */
    #[OAS\Operation\Patch(
        path: '/tags/{tag_id}',
        operationId: 'updateTag',
        summary: 'Update a tag (the fields given)',
        description: 'PUT is accepted too, and also leaves out fields as they are.',
        tags: ['Tags'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [new OAS\Parameter(name: 'tag_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                properties: [
                    new OAS\Property(property: 'tag_name', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'category_id', schema: new OAS\Schema(type: 'integer')),
                ],
            ),
        ),
        responses: [
            new OAS\Response(response: 200, description: 'The updated tag', content: new OAS\MediaType\Json(ref: '#/components/schemas/Tag')),
            new OAS\Response(response: 400, description: 'InvalidTagName / TagNameTooLong / TagAlreadyExists / InvalidCategoryID', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 404, description: 'TagNotFound', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function update(TagRequest $request, Tag $tag): TagResource
    {
        $tag->update($request->tag());

        Log::info('Tag edited', ['tag_id' => $tag->tag_id, 'tag_name' => $tag->tag_name, 'category_id' => $tag->category_id]);

        return new TagResource($tag);
    }

    /**
     * DELETE /tags/{tag_id} — Delete a tag.
     * DELETE /tags/{tag_id}/migrate-to/{target_tag_id} — move this tag's media
     * to the target first, then delete this tag.
     */
    #[OAS\Operation\Delete(
        path: '/tags/{tag_id}',
        operationId: 'deleteTag',
        summary: 'Delete a tag',
        tags: ['Tags'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [new OAS\Parameter(name: 'tag_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        responses: [
            new OAS\Response(response: 204, description: 'Deleted'),
            new OAS\Response(response: 404, description: 'TagNotFound', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    #[OAS\Operation\Delete(
        path: '/tags/{tag_id}/migrate-to/{target_tag_id}',
        operationId: 'migrateAndDeleteTag',
        summary: 'Move a tag\'s media to another tag, then delete it',
        tags: ['Tags'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'tag_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
            new OAS\Parameter(name: 'target_tag_id', in: 'path', required: true, description: 'Move media to this tag before deleting.', schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(response: 204, description: 'Moved and deleted'),
            new OAS\Response(response: 400, description: 'CannotMigrateToSelf', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 404, description: 'TagNotFound', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(Tag $tag, ?Tag $target = null): Response
    {
        if ($target?->tag_id === $tag->tag_id) {
            throw new ApiException('CannotMigrateToSelf', 400, 'A tag cannot be migrated to itself.');
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
     * POST /tags/{tag_id}/migrate — Move all this tag's media to the target,
     * keeping this tag (now on no media). Body: { target_tag_id }. To move and
     * delete in one step, DELETE /tags/{tag_id}/migrate-to/{target_tag_id}.
     */
    #[OAS\Operation\Post(
        path: '/tags/{tag_id}/migrate',
        operationId: 'migrateTag',
        summary: 'Move a tag\'s media to another tag, keeping it',
        tags: ['Tags'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
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
            new OAS\Response(response: 400, description: 'InvalidTagID / CannotMigrateToSelf', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 404, description: 'TagNotFound', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function migrate(Request $request, Tag $tag): JsonResponse
    {
        $targetId = $request->integer('target_tag_id');
        if ($targetId <= 0) {
            throw new ApiException('InvalidTagID', 400, 'The target tag ID must be a positive number.');
        }
        if ($targetId === $tag->tag_id) {
            throw new ApiException('CannotMigrateToSelf', 400, 'A tag cannot be migrated to itself.');
        }
        $target = Tag::query()->find($targetId) ?? throw new ApiException('TagNotFound', 404, 'The target tag could not be found.');

        $tag->moveMediaTo($target);

        Log::info('Tag migrated', ['source' => $tag->tag_id, 'target' => $target->tag_id]);

        return new JsonResponse(['migrated' => true, 'source_tag_id' => $tag->tag_id, 'target_tag_id' => $target->tag_id]);
    }

    /**
     * The positive ids in a comma-separated list.
     *
     * @return list<int>
     */
    private static function ids(string $list): array
    {
        return array_values(array_unique(array_filter(array_map(intval(...), explode(',', $list)), static fn(int $id): bool => $id > 0)));
    }
}
