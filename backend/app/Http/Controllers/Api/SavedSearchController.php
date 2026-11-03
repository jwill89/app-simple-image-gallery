<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SavedSearchRequest;
use App\Http\Resources\SavedSearchResource;
use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use OpenApi\Spec as OAS;

/**
 * The signed-in account's saved searches: tag searches kept by name, to run
 * again from the navbar. Someone else's is a 404, as a missing one is.
 */
final class SavedSearchController extends Controller
{
    /**
     * GET /saved-searches — The account's saved searches, by name.
     */
    #[OAS\Operation\Get(
        path: '/saved-searches',
        operationId: 'listSavedSearches',
        summary: 'Your saved searches',
        tags: ['Saved searches'],
        security: [new OAS\Security\Requirement(scheme: 'bearerAuth')],
        responses: [
            new OAS\Response(response: 200, description: 'The searches', content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/SavedSearch'))),
        ],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        return SavedSearchResource::collection(self::user($request)->savedSearches()->orderBy('name')->get());
    }

    /**
     * POST /saved-searches — Save a search. Body: { name, query }.
     */
    #[OAS\Operation\Post(
        path: '/saved-searches',
        operationId: 'createSavedSearch',
        summary: 'Save a search',
        tags: ['Saved searches'],
        security: [new OAS\Security\Requirement(scheme: 'bearerAuth')],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(required: ['name', 'query'], properties: [
                new OAS\Property(property: 'name', schema: new OAS\Schema(type: 'string', maxLength: 60)),
                new OAS\Property(property: 'query', schema: new OAS\Schema(type: 'string', maxLength: 500)),
            ]),
        ),
        responses: [
            new OAS\Response(response: 201, description: 'The search', content: new OAS\MediaType\Json(ref: '#/components/schemas/SavedSearch')),
            new OAS\Response(response: 400, description: 'InvalidSearchName, InvalidQuery', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 409, description: 'SearchExists', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function store(SavedSearchRequest $request): JsonResponse
    {
        $search = self::user($request)->savedSearches()->create([
            'name' => $request->string('name')->toString(),
            'query' => $request->string('query')->toString(),
        ]);

        return new SavedSearchResource($search)->response()->setStatusCode(201);
    }

    /**
     * PATCH /saved-searches/{search_id} — Rename a search or change its query.
     * Body: { name?, query? }.
     */
    #[OAS\Operation\Patch(
        path: '/saved-searches/{search_id}',
        operationId: 'updateSavedSearch',
        summary: 'Change a saved search',
        tags: ['Saved searches'],
        security: [new OAS\Security\Requirement(scheme: 'bearerAuth')],
        parameters: [new OAS\Parameter(name: 'search_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(properties: [
                new OAS\Property(property: 'name', schema: new OAS\Schema(type: 'string', maxLength: 60)),
                new OAS\Property(property: 'query', schema: new OAS\Schema(type: 'string', maxLength: 500)),
            ]),
        ),
        responses: [
            new OAS\Response(response: 200, description: 'The search', content: new OAS\MediaType\Json(ref: '#/components/schemas/SavedSearch')),
            new OAS\Response(response: 400, description: 'InvalidSearchName, InvalidQuery', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 404, description: 'SearchNotFound', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 409, description: 'SearchExists', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function update(SavedSearchRequest $request, SavedSearch $search): SavedSearchResource
    {
        self::own($request, $search)->update(array_filter([
            'name' => $request->has('name') ? $request->string('name')->toString() : null,
            'query' => $request->has('query') ? $request->string('query')->toString() : null,
        ], static fn(?string $value): bool => $value !== null));

        return new SavedSearchResource($search);
    }

    /**
     * DELETE /saved-searches/{search_id} — Delete a saved search.
     */
    #[OAS\Operation\Delete(
        path: '/saved-searches/{search_id}',
        operationId: 'deleteSavedSearch',
        summary: 'Delete a saved search',
        tags: ['Saved searches'],
        security: [new OAS\Security\Requirement(scheme: 'bearerAuth')],
        parameters: [new OAS\Parameter(name: 'search_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        responses: [
            new OAS\Response(response: 204, description: 'Deleted'),
            new OAS\Response(response: 404, description: 'SearchNotFound', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(Request $request, SavedSearch $search): Response
    {
        self::own($request, $search)->delete();

        return response()->noContent();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new ApiException('Unauthorized', 401, 'Authentication is required. Please log in and try again.');
    }

    /** The search, if it is the signed-in account's; someone else's is as missing as a deleted one. */
    private static function own(Request $request, SavedSearch $search): SavedSearch
    {
        return $search->user_id === self::user($request)->id
            ? $search
            : throw new ApiException('SearchNotFound', 404, 'The saved search could not be found.');
    }
}
