<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DanbooruCategoryMappingRequest;
use App\Http\Resources\CategoryMappingResource;
use App\Models\DanbooruCategoryMapping;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use OpenApi\Spec as OAS;

/**
 * Danbooru import rules: which gallery category each Danbooru tag category
 * imports into. No cached response depends on them.
 */
final class DanbooruCategoryMappingController extends Controller
{
    /**
     * GET /danbooru/category-mappings — All Danbooru→gallery category mappings.
     */
    #[OAS\Operation\Get(
        path: '/danbooru/category-mappings',
        operationId: 'listDanbooruCategoryMappings',
        summary: 'List category mappings',
        tags: ['Danbooru'],
        responses: [
            new OAS\Response(response: 200, description: 'Category mappings', content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/CategoryMapping'))),
        ],
    )]
    public function index(): AnonymousResourceCollection
    {
        return CategoryMappingResource::collection(DanbooruCategoryMapping::query()->with('category')->orderBy('danbooru_category_id')->get());
    }

    /**
     * POST /danbooru/category-mappings — Map a Danbooru category to a gallery
     * category. Body: { danbooru_category_id, danbooru_category_name, gallery_category_id }.
     */
    #[OAS\Operation\Post(
        path: '/danbooru/category-mappings',
        operationId: 'saveDanbooruCategoryMapping',
        summary: 'Create a category mapping',
        tags: ['Danbooru'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        requestBody: new OAS\RequestBody(required: true, content: new OAS\MediaType\Json(
            required: ['danbooru_category_id', 'danbooru_category_name', 'gallery_category_id'],
            properties: [
                new OAS\Property(property: 'danbooru_category_id', schema: new OAS\Schema(type: 'integer')),
                new OAS\Property(property: 'danbooru_category_name', schema: new OAS\Schema(type: 'string')),
                new OAS\Property(property: 'gallery_category_id', schema: new OAS\Schema(type: 'integer')),
            ],
        )),
        responses: [
            new OAS\Response(response: 201, description: 'The created mapping', content: new OAS\MediaType\Json(ref: '#/components/schemas/CategoryMapping')),
            new OAS\Response(response: 400, description: 'InvalidInput', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 404, description: 'CategoryNotFound', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function store(DanbooruCategoryMappingRequest $request): JsonResponse
    {
        $fields = $request->mapping();

        // A Danbooru category that is mapped already gets the new mapping,
        // answered 201 all the same.
        $mapping = DanbooruCategoryMapping::query()->updateOrCreate(['danbooru_category_id' => $fields['danbooru_category_id']], $fields);

        Log::info('Danbooru category mapping saved', [
            'danbooru_category_id' => $mapping->danbooru_category_id,
            'gallery_category_id' => $mapping->gallery_category_id,
        ]);

        return new CategoryMappingResource($mapping)->response()->setStatusCode(201);
    }

    /**
     * DELETE /danbooru/category-mappings/{danbooru_category_id} — Remove a mapping.
     */
    #[OAS\Operation\Delete(
        path: '/danbooru/category-mappings/{danbooru_category_id}',
        operationId: 'deleteDanbooruCategoryMapping',
        summary: 'Delete a category mapping',
        tags: ['Danbooru'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [new OAS\Parameter(name: 'danbooru_category_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        responses: [
            new OAS\Response(response: 204, description: 'Deleted'),
            new OAS\Response(response: 404, description: 'MappingNotFound', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(DanbooruCategoryMapping $mapping): Response
    {
        // Danbooru's category ids start at 0, which the binding takes as any other.
        $mapping->delete();
        Log::info('Danbooru category mapping deleted', ['danbooru_category_id' => $mapping->danbooru_category_id]);

        return response()->noContent();
    }
}
