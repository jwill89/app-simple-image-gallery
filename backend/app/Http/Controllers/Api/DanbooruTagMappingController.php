<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DanbooruTagMappingRequest;
use App\Http\Resources\TagMappingResource;
use App\Models\DanbooruTagMapping;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use OpenApi\Spec as OAS;

/**
 * Danbooru import rules: Danbooru tag names imported under another gallery
 * tag name. No cached response depends on them.
 */
final class DanbooruTagMappingController extends Controller
{
    /**
     * GET /danbooru/tag-mappings — All Danbooru→gallery tag-name mappings.
     */
    #[OAS\Operation\Get(
        path: '/danbooru/tag-mappings',
        operationId: 'listDanbooruTagMappings',
        summary: 'List tag name mappings',
        tags: ['Danbooru'],
        responses: [
            new OAS\Response(response: 200, description: 'Tag name mappings', content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/TagMapping'))),
        ],
    )]
    public function index(): AnonymousResourceCollection
    {
        return TagMappingResource::collection(DanbooruTagMapping::query()->orderBy('danbooru_tag')->get());
    }

    /**
     * POST /danbooru/tag-mappings — Map a Danbooru tag name to a gallery tag name.
     * Body: { danbooru_tag, gallery_tag }.
     */
    #[OAS\Operation\Post(
        path: '/danbooru/tag-mappings',
        operationId: 'createDanbooruTagMapping',
        summary: 'Create a tag name mapping',
        tags: ['Danbooru'],
        security: [new OAS\Security\Requirement(scheme: 'bearerAuth')],
        requestBody: new OAS\RequestBody(required: true, content: new OAS\MediaType\Json(
            required: ['danbooru_tag', 'gallery_tag'],
            properties: [
                new OAS\Property(property: 'danbooru_tag', schema: new OAS\Schema(type: 'string')),
                new OAS\Property(property: 'gallery_tag', schema: new OAS\Schema(type: 'string')),
            ],
        )),
        responses: [
            new OAS\Response(response: 201, description: 'The created mapping', content: new OAS\MediaType\Json(ref: '#/components/schemas/TagMapping')),
            new OAS\Response(response: 400, description: 'InvalidInput / DuplicateMapping', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function store(DanbooruTagMappingRequest $request): TagMappingResource
    {
        $mapping = DanbooruTagMapping::query()->create($request->mapping());
        Log::info('Danbooru tag mapping added', ['danbooru_tag' => $mapping->danbooru_tag, 'gallery_tag' => $mapping->gallery_tag]);

        return new TagMappingResource($mapping);
    }

    /**
     * PUT /danbooru/tag-mappings/{id} — Update a tag-name mapping.
     * Body: { danbooru_tag, gallery_tag }.
     */
    #[OAS\Operation\Put(
        path: '/danbooru/tag-mappings/{id}',
        operationId: 'updateDanbooruTagMapping',
        summary: 'Update a tag name mapping',
        tags: ['Danbooru'],
        security: [new OAS\Security\Requirement(scheme: 'bearerAuth')],
        parameters: [new OAS\Parameter(name: 'id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        requestBody: new OAS\RequestBody(required: true, content: new OAS\MediaType\Json(
            required: ['danbooru_tag', 'gallery_tag'],
            properties: [
                new OAS\Property(property: 'danbooru_tag', schema: new OAS\Schema(type: 'string')),
                new OAS\Property(property: 'gallery_tag', schema: new OAS\Schema(type: 'string')),
            ],
        )),
        responses: [
            new OAS\Response(response: 200, description: 'The updated mapping', content: new OAS\MediaType\Json(ref: '#/components/schemas/TagMapping')),
            new OAS\Response(response: 400, description: 'InvalidInput / DuplicateMapping', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 404, description: 'MappingNotFound', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function update(DanbooruTagMappingRequest $request, DanbooruTagMapping $mapping): TagMappingResource
    {
        $mapping->update($request->mapping());
        Log::info('Danbooru tag mapping updated', ['id' => $mapping->id, 'danbooru_tag' => $mapping->danbooru_tag]);

        return new TagMappingResource($mapping);
    }

    /**
     * DELETE /danbooru/tag-mappings/{id} — Remove a tag-name mapping.
     */
    #[OAS\Operation\Delete(
        path: '/danbooru/tag-mappings/{id}',
        operationId: 'deleteDanbooruTagMapping',
        summary: 'Delete a tag name mapping',
        tags: ['Danbooru'],
        security: [new OAS\Security\Requirement(scheme: 'bearerAuth')],
        parameters: [new OAS\Parameter(name: 'id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        responses: [
            new OAS\Response(response: 204, description: 'Deleted'),
            new OAS\Response(response: 404, description: 'MappingNotFound', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(DanbooruTagMapping $mapping): Response
    {
        $mapping->delete();
        Log::info('Danbooru tag mapping deleted', ['id' => $mapping->id]);

        return response()->noContent();
    }
}
