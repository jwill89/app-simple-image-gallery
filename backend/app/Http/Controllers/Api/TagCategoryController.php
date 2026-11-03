<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\TagCategoryRequest;
use App\Http\Resources\TagCategoryResource;
use App\Models\TagCategory;
use App\Support\CacheGroup;
use App\Support\ResponseCache;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use OpenApi\Spec as OAS;

/** CRUD for tag categories (the `/tag-categories` resource). */
final class TagCategoryController extends Controller
{
    public function __construct(private readonly ResponseCache $cache) {}

    /**
     * GET /tag-categories — All tag categories.
     */
    #[OAS\Operation\Get(
        path: '/tag-categories',
        operationId: 'listTagCategories',
        summary: 'List tag categories',
        tags: ['Tag Categories'],
        responses: [
            new OAS\Response(response: 200, description: 'All categories', content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/TagCategory'))),
        ],
    )]
    public function index(): AnonymousResourceCollection
    {
        return TagCategoryResource::collection(TagCategory::query()->orderBy('sort_order')->orderBy('category_name')->get());
    }

    /**
     * POST /tag-categories — Create a tag category. Returns the created category.
     * Body: { category_name, color?, description?, sort_order? }
     */
    #[OAS\Operation\Post(
        path: '/tag-categories',
        operationId: 'createTagCategory',
        summary: 'Create a tag category',
        tags: ['Tag Categories'],
        security: [new OAS\Security\Requirement(scheme: 'bearerAuth')],
        requestBody: new OAS\RequestBody(required: true, content: new OAS\MediaType\Json(
            required: ['category_name'],
            properties: [
                new OAS\Property(property: 'category_name', schema: new OAS\Schema(type: 'string')),
                new OAS\Property(property: 'color', schema: new OAS\Schema(type: 'string')),
                new OAS\Property(property: 'description', schema: new OAS\Schema(type: 'string')),
                new OAS\Property(property: 'sort_order', schema: new OAS\Schema(type: 'integer')),
            ],
        )),
        responses: [
            new OAS\Response(response: 201, description: 'The created category', content: new OAS\MediaType\Json(ref: '#/components/schemas/TagCategory')),
            new OAS\Response(response: 400, description: 'Validation error', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function store(TagCategoryRequest $request): TagCategoryResource
    {
        $category = TagCategory::query()->create($request->category());

        $this->cache->bump(CacheGroup::Tags);
        Log::info('Category created', ['category_id' => $category->category_id, 'name' => $category->category_name]);

        return new TagCategoryResource($category);
    }

    /**
     * PUT /tag-categories/{category_id} — Update a tag category.
     * Returns the updated category. Body: same fields as create.
     */
    #[OAS\Operation\Put(
        path: '/tag-categories/{category_id}',
        operationId: 'updateTagCategory',
        summary: 'Update a tag category',
        tags: ['Tag Categories'],
        security: [new OAS\Security\Requirement(scheme: 'bearerAuth')],
        parameters: [new OAS\Parameter(name: 'category_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        requestBody: new OAS\RequestBody(required: true, content: new OAS\MediaType\Json(
            required: ['category_name'],
            properties: [
                new OAS\Property(property: 'category_name', schema: new OAS\Schema(type: 'string')),
                new OAS\Property(property: 'color', schema: new OAS\Schema(type: 'string')),
                new OAS\Property(property: 'description', schema: new OAS\Schema(type: 'string')),
                new OAS\Property(property: 'sort_order', schema: new OAS\Schema(type: 'integer')),
            ],
        )),
        responses: [
            new OAS\Response(response: 200, description: 'The updated category', content: new OAS\MediaType\Json(ref: '#/components/schemas/TagCategory')),
            new OAS\Response(response: 404, description: 'CategoryNotFound', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function update(TagCategoryRequest $request, string $categoryId): TagCategoryResource
    {
        $category = $this->find($categoryId);
        $category->update($request->category());

        $this->cache->bump(CacheGroup::Tags);
        Log::info('Category edited', ['category_id' => $category->category_id, 'name' => $category->category_name]);

        return new TagCategoryResource($category);
    }

    /**
     * DELETE /tag-categories/{category_id} — Delete a category that has no tags.
     */
    #[OAS\Operation\Delete(
        path: '/tag-categories/{category_id}',
        operationId: 'deleteTagCategory',
        summary: 'Delete a tag category',
        tags: ['Tag Categories'],
        security: [new OAS\Security\Requirement(scheme: 'bearerAuth')],
        parameters: [new OAS\Parameter(name: 'category_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        responses: [
            new OAS\Response(response: 204, description: 'Deleted'),
            new OAS\Response(response: 400, description: 'CategoryInUse', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 404, description: 'CategoryNotFound', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(string $categoryId): Response
    {
        $category = $this->find($categoryId);

        $tagCount = $category->tags()->count();
        if ($tagCount > 0) {
            throw new ApiException(
                'CategoryInUse',
                400,
                "Cannot delete \"{$category->category_name}\" because {$tagCount} tag(s) belong to it. Reassign them first.",
            );
        }

        // Its Danbooru category mapping goes with it (ON DELETE CASCADE).
        $category->delete();

        $this->cache->bump(CacheGroup::Tags);
        Log::info('Category deleted', ['category_id' => $category->category_id]);

        return response()->noContent();
    }

    private function find(string $categoryId): TagCategory
    {
        $id = self::positiveId($categoryId) ?? throw new ApiException('InvalidInput', 400, 'A valid category ID is required.');

        return TagCategory::query()->find($id) ?? throw new ApiException('CategoryNotFound', 404, 'The category does not exist.');
    }
}
