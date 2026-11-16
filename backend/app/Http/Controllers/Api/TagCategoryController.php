<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\TagCategoryRequest;
use App\Http\Resources\TagCategoryResource;
use App\Models\TagCategory;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use App\OpenApi\ProblemJson;
use OpenApi\Spec as OAS;

/**
 * CRUD for tag categories (the `/tag-categories` resource). Writes clear the
 * cached tag lists through the TagCategory model.
 */
final class TagCategoryController extends Controller
{
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
     * Body: { categoryName, color, description?, sortOrder? }
     */
    #[OAS\Operation\Post(
        path: '/tag-categories',
        operationId: 'createTagCategory',
        summary: 'Create a tag category',
        tags: ['Tag Categories'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        requestBody: new OAS\RequestBody(required: true, content: new OAS\MediaType\Json(ref: '#/components/schemas/TagCategoryCreateBody')),
        responses: [
            new OAS\Response(response: 201, description: 'The created category', content: new OAS\MediaType\Json(ref: '#/components/schemas/TagCategory')),
            new OAS\Response(response: 403, description: 'Forbidden (not an admin)', content: new ProblemJson()),
            new OAS\Response(response: 409, description: 'NameTaken', content: new ProblemJson()),
            new OAS\Response(response: 422, description: 'InvalidInput, NameTooLong, InvalidColor', content: new ProblemJson()),
        ],
    )]
    public function store(TagCategoryRequest $request): TagCategoryResource
    {
        $category = TagCategory::query()->create($request->category());

        Log::info('Category created', ['category_id' => $category->category_id, 'name' => $category->category_name]);

        return new TagCategoryResource($category);
    }

    /**
     * PATCH /tag-categories/{categoryId} — Update a tag category: the
     * fields sent, the rest as they are. Returns the updated category.
     */
    #[OAS\Operation\Patch(
        path: '/tag-categories/{categoryId}',
        operationId: 'updateTagCategory',
        summary: 'Update a tag category (the fields given)',
        tags: ['Tag Categories'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [new OAS\Parameter(name: 'categoryId', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        requestBody: new OAS\RequestBody(required: true, content: new OAS\MediaType\Json(ref: '#/components/schemas/TagCategoryUpdateBody')),
        responses: [
            new OAS\Response(response: 200, description: 'The updated category', content: new OAS\MediaType\Json(ref: '#/components/schemas/TagCategory')),
            new OAS\Response(response: 403, description: 'Forbidden (not an admin)', content: new ProblemJson()),
            new OAS\Response(response: 404, description: 'CategoryNotFound', content: new ProblemJson()),
            new OAS\Response(response: 409, description: 'NameTaken', content: new ProblemJson()),
            new OAS\Response(response: 422, description: 'InvalidInput, NameTooLong, InvalidColor', content: new ProblemJson()),
        ],
    )]
    public function update(TagCategoryRequest $request, TagCategory $category): TagCategoryResource
    {
        $category->update($request->category());

        Log::info('Category edited', ['category_id' => $category->category_id, 'name' => $category->category_name]);

        return new TagCategoryResource($category);
    }

    /**
     * DELETE /tag-categories/{categoryId} — Delete a category that has no tags.
     */
    #[OAS\Operation\Delete(
        path: '/tag-categories/{categoryId}',
        operationId: 'deleteTagCategory',
        summary: 'Delete a tag category',
        tags: ['Tag Categories'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [new OAS\Parameter(name: 'categoryId', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        responses: [
            new OAS\Response(response: 204, description: 'Deleted'),
            new OAS\Response(response: 403, description: 'Forbidden (not an admin)', content: new ProblemJson()),
            new OAS\Response(response: 404, description: 'CategoryNotFound', content: new ProblemJson()),
            new OAS\Response(response: 409, description: 'CategoryInUse', content: new ProblemJson()),
        ],
    )]
    public function destroy(TagCategory $category): Response
    {
        $tagCount = $category->tags()->count();
        if ($tagCount > 0) {
            throw new ApiException(
                'CategoryInUse',
                409,
                "Cannot delete \"{$category->category_name}\" because {$tagCount} tag(s) belong to it. Reassign them first.",
            );
        }

        // Its Danbooru category mapping goes with it (ON DELETE CASCADE).
        $category->delete();

        Log::info('Category deleted', ['category_id' => $category->category_id]);

        return response()->noContent();
    }
}
