<?php

namespace Routes\Internal;

use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Gallery\Core\CacheGroup;
use Gallery\Core\ResponseCache;
use Gallery\Repository\TagRepository;
use Gallery\Structure\Tag;
use OpenApi\Spec as OAS;

/**
 * TagImplicationController
 * The `/tag-implications` resource: applying a tag transitively applies the
 * tags it implies.
 */
class TagImplicationController extends AbstractController
{
    public function __construct(private readonly TagRepository $tag_repository)
    {
        parent::__construct();
    }

    /**
     * GET /tag-implications — All tag implications. Cached.
     */
    #[OAS\Operation\Get(
        path: '/tag-implications',
        summary: 'List tag implications',
        tags: ['Tag Implications'],
        responses: [
            new OAS\Response(response: 200, description: 'All implications', content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/TagImplication'))),
        ]
    )]
    public function getImplications(Request $request, Response $response): Response
    {
        return $this->cachedSuccess($response, CacheGroup::Tags, 'implications', ResponseCache::TTL_MEDIUM, fn() => $this->tag_repository->getAllImplications());
    }

    /**
     * POST /tag-implications — Add "tag_id implies implied_tag_id".
     * Body: { tag_id, implied_tag_id }. Rejects cycles and self-implication.
     * Returns the created implication.
     */
    #[OAS\Operation\Post(
        path: '/tag-implications',
        summary: 'Create a tag implication',
        tags: ['Tag Implications'],
        security: [new OAS\Security\Requirement(scheme: 'bearerAuth')],
        requestBody: new OAS\RequestBody(required: true, content: new OAS\MediaType\Json(
            required: ['tag_id', 'implied_tag_id'],
            properties: [
                new OAS\Property(property: 'tag_id', schema: new OAS\Schema(type: 'integer')),
                new OAS\Property(property: 'implied_tag_id', schema: new OAS\Schema(type: 'integer')),
            ]
        )),
        responses: [
            new OAS\Response(response: 201, description: 'The created implication', content: new OAS\MediaType\Json(ref: '#/components/schemas/TagImplication')),
            new OAS\Response(response: 400, description: 'CannotImplySelf / CycleDetected', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 404, description: 'TagDoesNotExist', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function addImplication(Request $request, Response $response): Response
    {
        $params = $this->parsedBody($request);
        $tag_id = $this->intParam($params, 'tag_id', 0);
        $implied_tag_id = $this->intParam($params, 'implied_tag_id', 0);

        if ($tag_id <= 0 || $implied_tag_id <= 0) {
            return $this->error($response, 'InvalidTagID', 400, 'Both tag IDs must be positive numbers.');
        }

        if ($tag_id === $implied_tag_id) {
            return $this->error($response, 'CannotImplySelf', 400, 'A tag cannot imply itself.');
        }

        $tag = $this->tag_repository->get($tag_id);
        $impliedTag = $this->tag_repository->get($implied_tag_id);

        if (!($tag instanceof Tag) || !($impliedTag instanceof Tag)) {
            return $this->error($response, 'TagDoesNotExist', 404, 'One or both of the specified tags could not be found.');
        }

        $success = $this->tag_repository->addImplication($tag_id, $implied_tag_id);

        if (!$success) {
            return $this->error($response, 'CycleDetected', 400, 'This implication would create a circular dependency.');
        }

        $this->invalidateCache(CacheGroup::Tags);
        $this->logger->info('Tag implication added', ['tag_id' => $tag_id, 'implied_tag_id' => $implied_tag_id]);
        return $this->created($response, [
            'tag_id' => $tag_id,
            'tag_name' => $tag->tag_name,
            'implied_tag_id' => $implied_tag_id,
            'implied_tag_name' => $impliedTag->tag_name,
        ]);
    }

    /**
     * DELETE /tag-implications/{tag_id}/{implied_tag_id} — Remove an implication.
     */
    #[OAS\Operation\Delete(
        path: '/tag-implications/{tag_id}/{implied_tag_id}',
        summary: 'Remove a tag implication',
        tags: ['Tag Implications'],
        security: [new OAS\Security\Requirement(scheme: 'bearerAuth')],
        parameters: [
            new OAS\Parameter(name: 'tag_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
            new OAS\Parameter(name: 'implied_tag_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(response: 204, description: 'Removed'),
            new OAS\Response(response: 400, description: 'InvalidTagID', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function removeImplication(Request $request, Response $response, array $args): Response
    {
        $tag_id = $this->intParam($args, 'tag_id', 0);
        $implied_tag_id = $this->intParam($args, 'implied_tag_id', 0);

        if ($tag_id <= 0 || $implied_tag_id <= 0) {
            return $this->error($response, 'InvalidTagID', 400, 'Both tag IDs must be positive numbers.');
        }

        $success = $this->tag_repository->removeImplication($tag_id, $implied_tag_id);

        if (!$success) {
            $this->logger->error('Failed to remove tag implication', ['tag_id' => $tag_id, 'implied_tag_id' => $implied_tag_id]);
            return $this->error($response, 'CouldNotRemoveImplication', 500, 'The implication could not be removed. Please try again.');
        }

        $this->invalidateCache(CacheGroup::Tags);
        $this->logger->info('Tag implication removed', ['tag_id' => $tag_id, 'implied_tag_id' => $implied_tag_id]);
        return $this->noContent($response);
    }
}
