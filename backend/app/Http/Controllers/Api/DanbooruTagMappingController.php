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
use App\OpenApi\ProblemJson;
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
     * Body: { danbooruTag, galleryTag }.
     */
    #[OAS\Operation\Post(
        path: '/danbooru/tag-mappings',
        operationId: 'createDanbooruTagMapping',
        summary: 'Create a tag name mapping',
        tags: ['Danbooru'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        requestBody: new OAS\RequestBody(required: true, content: new OAS\MediaType\Json(ref: '#/components/schemas/TagMappingCreateBody')),
        responses: [
            new OAS\Response(response: 201, description: 'The created mapping', content: new OAS\MediaType\Json(ref: '#/components/schemas/TagMapping')),
            new OAS\Response(response: 403, description: 'Forbidden (not an admin)', content: new ProblemJson()),
            new OAS\Response(response: 409, description: 'DuplicateMapping', content: new ProblemJson()),
            new OAS\Response(response: 422, description: 'InvalidInput', content: new ProblemJson()),
        ],
    )]
    public function store(DanbooruTagMappingRequest $request): TagMappingResource
    {
        $mapping = DanbooruTagMapping::query()->create($request->mapping());
        Log::info('Danbooru tag mapping added', ['danbooru_tag' => $mapping->danbooru_tag, 'gallery_tag' => $mapping->gallery_tag]);

        return new TagMappingResource($mapping);
    }

    /**
     * PATCH /danbooru/tag-mappings/{mappingId} — Change a tag-name mapping.
     * Body: { danbooruTag?, galleryTag? }; a field left out stays as it is.
     */
    #[OAS\Operation\Patch(
        path: '/danbooru/tag-mappings/{mappingId}',
        operationId: 'updateDanbooruTagMapping',
        summary: 'Update a tag name mapping (the fields given)',
        tags: ['Danbooru'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [new OAS\Parameter(name: 'mappingId', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        requestBody: new OAS\RequestBody(required: true, content: new OAS\MediaType\Json(ref: '#/components/schemas/TagMappingUpdateBody')),
        responses: [
            new OAS\Response(response: 200, description: 'The updated mapping', content: new OAS\MediaType\Json(ref: '#/components/schemas/TagMapping')),
            new OAS\Response(response: 403, description: 'Forbidden (not an admin)', content: new ProblemJson()),
            new OAS\Response(response: 404, description: 'MappingNotFound', content: new ProblemJson()),
            new OAS\Response(response: 409, description: 'DuplicateMapping', content: new ProblemJson()),
            new OAS\Response(response: 422, description: 'InvalidInput', content: new ProblemJson()),
        ],
    )]
    public function update(DanbooruTagMappingRequest $request, DanbooruTagMapping $mapping): TagMappingResource
    {
        $mapping->update($request->mapping());
        Log::info('Danbooru tag mapping updated', ['id' => $mapping->id, 'danbooru_tag' => $mapping->danbooru_tag]);

        return new TagMappingResource($mapping);
    }

    /**
     * DELETE /danbooru/tag-mappings/{mappingId} — Remove a tag-name mapping.
     */
    #[OAS\Operation\Delete(
        path: '/danbooru/tag-mappings/{mappingId}',
        operationId: 'deleteDanbooruTagMapping',
        summary: 'Delete a tag name mapping',
        tags: ['Danbooru'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [new OAS\Parameter(name: 'mappingId', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        responses: [
            new OAS\Response(response: 204, description: 'Deleted'),
            new OAS\Response(response: 403, description: 'Forbidden (not an admin)', content: new ProblemJson()),
            new OAS\Response(response: 404, description: 'MappingNotFound', content: new ProblemJson()),
        ],
    )]
    public function destroy(DanbooruTagMapping $mapping): Response
    {
        $mapping->delete();
        Log::info('Danbooru tag mapping deleted', ['id' => $mapping->id]);

        return response()->noContent();
    }
}
