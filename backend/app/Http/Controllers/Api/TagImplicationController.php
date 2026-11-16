<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Tag;
use App\Support\CacheGroup;
use App\Support\ResponseCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\OpenApi\ProblemJson;
use OpenApi\Spec as OAS;

/** "Tag A implies tag B": applying A applies B too, transitively. */
final class TagImplicationController extends Controller
{
    public function __construct(private readonly ResponseCache $cache) {}

    /**
     * GET /tag-implications — All tag implications. Cached.
     */
    #[OAS\Operation\Get(
        path: '/tag-implications',
        operationId: 'listTagImplications',
        summary: 'List tag implications',
        tags: ['Tag Implications'],
        responses: [
            new OAS\Response(response: 200, description: 'All implications', content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/TagImplication'))),
        ],
    )]
    public function index(): JsonResponse
    {
        return $this->cache->remember(CacheGroup::Tags, 'implications', ResponseCache::TTL_MEDIUM, static fn() => DB::table('tag_implications')
            ->join('tags as tag', 'tag.tag_id', '=', 'tag_implications.tag_id')
            ->join('tags as implied', 'implied.tag_id', '=', 'tag_implications.implied_tag_id')
            ->select(['tag_implications.tag_id as tagId', 'tag.tag_name as tagName', 'tag_implications.implied_tag_id as impliedTagId', 'implied.tag_name as impliedTagName'])
            ->orderBy('tag.tag_name')
            ->orderBy('implied.tag_name')
            ->get());
    }

    /**
     * POST /tag-implications — Add "tagId implies impliedTagId".
     * Body: { tagId, impliedTagId }. Rejects cycles and self-implication.
     * Returns the created implication.
     */
    #[OAS\Operation\Post(
        path: '/tag-implications',
        operationId: 'createTagImplication',
        summary: 'Create a tag implication',
        tags: ['Tag Implications'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        requestBody: new OAS\RequestBody(required: true, content: new OAS\MediaType\Json(ref: '#/components/schemas/TagImplicationBody')),
        responses: [
            new OAS\Response(response: 201, description: 'The created implication', content: new OAS\MediaType\Json(ref: '#/components/schemas/TagImplication')),
            new OAS\Response(response: 403, description: 'Forbidden (not an admin)', content: new ProblemJson()),
            new OAS\Response(response: 409, description: 'CycleDetected', content: new ProblemJson()),
            new OAS\Response(response: 422, description: 'InvalidTagID, CannotImplySelf, TagNotFound (either tag)', content: new ProblemJson()),
        ],
    )]
    public function store(Request $request): JsonResponse
    {
        $tagId = $request->integer('tagId');
        $impliedId = $request->integer('impliedTagId');
        if ($tagId <= 0 || $impliedId <= 0) {
            throw self::invalid('InvalidTagID', 'Both tag IDs must be positive numbers.');
        }
        if ($tagId === $impliedId) {
            throw self::invalid('CannotImplySelf', 'A tag cannot imply itself.');
        }

        $tag = Tag::query()->find($tagId);
        $implied = Tag::query()->find($impliedId);
        if ($tag === null || $implied === null) {
            throw self::invalid('TagNotFound', 'One or both of the specified tags could not be found.');
        }
        if (in_array($tagId, Tag::withImplied([$impliedId]), true)) {
            throw new ApiException('CycleDetected', 409, 'This implication would create a circular dependency.');
        }

        // Adding one that exists already is not an error.
        $tag->imply($implied);

        Log::info('Tag implication added', ['tag_id' => $tagId, 'implied_tag_id' => $impliedId]);

        return new JsonResponse([
            'tagId' => $tagId,
            'tagName' => $tag->tag_name,
            'impliedTagId' => $impliedId,
            'impliedTagName' => $implied->tag_name,
        ], 201);
    }

    private static function invalid(string $code, string $message): ApiException
    {
        return new ApiException($code, 422, $message, [], [['message' => $message, 'location' => 'body.impliedTagId']]);
    }

    /**
     * DELETE /tag-implications/{tagId}/{impliedTagId} — Remove an implication.
     */
    #[OAS\Operation\Delete(
        path: '/tag-implications/{tagId}/{impliedTagId}',
        operationId: 'deleteTagImplication',
        summary: 'Remove a tag implication',
        tags: ['Tag Implications'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'tagId', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
            new OAS\Parameter(name: 'impliedTagId', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(response: 204, description: 'Removed'),
            new OAS\Response(response: 403, description: 'Forbidden (not an admin)', content: new ProblemJson()),
            new OAS\Response(response: 404, description: 'TagNotFound, ImplicationNotFound', content: new ProblemJson()),
        ],
    )]
    public function destroy(Tag $tag, Tag $implied): Response
    {
        if (!$tag->implies()->whereKey($implied->tag_id)->exists()) {
            throw new ApiException('ImplicationNotFound', 404, "\"{$tag->tag_name}\" does not imply \"{$implied->tag_name}\".");
        }

        $tag->unimply($implied);

        Log::info('Tag implication removed', ['tag_id' => $tag->tag_id, 'implied_tag_id' => $implied->tag_id]);

        return response()->noContent();
    }
}
