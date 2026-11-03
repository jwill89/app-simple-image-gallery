<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\FavoriteListRequest;
use App\Http\Resources\FavoriteListResource;
use App\Http\Resources\MediaResource;
use App\Models\FavoriteList;
use App\Models\Media;
use App\Models\User;
use App\Support\Viewer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use OpenApi\Spec as OAS;

/**
 * The signed-in account's lists of media. Every account has a default list,
 * "Favorites", which the heart on a card adds to; it can be renamed but not
 * deleted. Someone else's list is a 404, as a missing one is.
 */
final class FavoriteListController extends Controller
{
    /** The most media ids one request may add to a list. */
    private const int MAX_ADD = 2000;

    /**
     * GET /lists — The account's lists: the default first, then by name.
     */
    #[OAS\Operation\Get(
        path: '/lists',
        operationId: 'listFavoriteLists',
        summary: 'Your lists',
        tags: ['Lists'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        responses: [
            new OAS\Response(response: 200, description: 'The lists', content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/FavoriteList'))),
        ],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = self::user($request);
        $user->defaultList();

        return FavoriteListResource::collection(
            $user->favoriteLists()->withCount('media')->orderByDesc('is_default')->orderBy('name')->get(),
        );
    }

    /**
     * POST /lists — Make a list. Body: { name }.
     */
    #[OAS\Operation\Post(
        path: '/lists',
        operationId: 'createFavoriteList',
        summary: 'Make a list',
        tags: ['Lists'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(required: ['name'], properties: [new OAS\Property(property: 'name', schema: new OAS\Schema(type: 'string', maxLength: 60))]),
        ),
        responses: [
            new OAS\Response(response: 201, description: 'The list', content: new OAS\MediaType\Json(ref: '#/components/schemas/FavoriteList')),
            new OAS\Response(response: 400, description: 'InvalidListName', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 409, description: 'ListExists', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function store(FavoriteListRequest $request): JsonResponse
    {
        $list = self::user($request)->favoriteLists()->create(['name' => $request->string('name')->toString()]);

        return new FavoriteListResource($list->loadCount('media'))->response()->setStatusCode(201);
    }

    /**
     * PATCH /lists/{list_id} — Rename a list. Body: { name }.
     */
    #[OAS\Operation\Patch(
        path: '/lists/{list_id}',
        operationId: 'renameFavoriteList',
        summary: 'Rename a list',
        tags: ['Lists'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [new OAS\Parameter(name: 'list_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(required: ['name'], properties: [new OAS\Property(property: 'name', schema: new OAS\Schema(type: 'string', maxLength: 60))]),
        ),
        responses: [
            new OAS\Response(response: 200, description: 'The list', content: new OAS\MediaType\Json(ref: '#/components/schemas/FavoriteList')),
            new OAS\Response(response: 400, description: 'InvalidListName', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 404, description: 'ListNotFound', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 409, description: 'ListExists', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function update(FavoriteListRequest $request, FavoriteList $list): FavoriteListResource
    {
        self::own($request, $list)->update(['name' => $request->string('name')->toString()]);

        return new FavoriteListResource($list->loadCount('media'));
    }

    /**
     * DELETE /lists/{list_id} — Delete a list (not its media). The default
     * list can't be.
     */
    #[OAS\Operation\Delete(
        path: '/lists/{list_id}',
        operationId: 'deleteFavoriteList',
        summary: 'Delete a list',
        tags: ['Lists'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [new OAS\Parameter(name: 'list_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        responses: [
            new OAS\Response(response: 204, description: 'Deleted'),
            new OAS\Response(response: 404, description: 'ListNotFound', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 409, description: 'DefaultList', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(Request $request, FavoriteList $list): Response
    {
        if (self::own($request, $list)->is_default) {
            throw new ApiException('DefaultList', 409, 'Your Favorites list can be renamed, but not deleted.');
        }
        $list->delete();

        return response()->noContent();
    }

    /**
     * GET /lists/{list_id}/ids — Every media id in a list, for marking cards.
     */
    #[OAS\Operation\Get(
        path: '/lists/{list_id}/ids',
        operationId: 'favoriteListIds',
        summary: 'The media ids in a list',
        tags: ['Lists'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [new OAS\Parameter(name: 'list_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        responses: [
            new OAS\Response(response: 200, description: 'The ids', content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(type: 'integer'))),
            new OAS\Response(response: 404, description: 'ListNotFound', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function ids(Request $request, FavoriteList $list): JsonResponse
    {
        return new JsonResponse(array_map(intval(...), self::own($request, $list)->media()->pluck('media.media_id')->all()));
    }

    /**
     * GET /lists/{list_id}/media?page=&per_page= — A page of a list, the most
     * recently added first. Media carrying a tag the account blocks is left
     * out, as from every listing.
     */
    #[OAS\Operation\Get(
        path: '/lists/{list_id}/media',
        operationId: 'favoriteListMedia',
        summary: 'A page of a list',
        tags: ['Lists'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'list_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
            new OAS\Parameter(name: 'page', in: 'query', required: false, schema: new OAS\Schema(type: 'integer', default: 1, minimum: 1)),
            new OAS\Parameter(name: 'per_page', in: 'query', required: false, schema: new OAS\Schema(type: 'integer', default: 40, maximum: 200, minimum: 1)),
        ],
        responses: [
            new OAS\Response(response: 200, description: 'A page of the list', content: new OAS\MediaType\Json(ref: '#/components/schemas/MediaPage')),
            new OAS\Response(response: 404, description: 'ListNotFound', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function media(Request $request, FavoriteList $list): JsonResponse
    {
        $query = self::own($request, $list)->media()->hidingTags(Viewer::hiddenTags($request));
        $page = max(1, $request->integer('page', 1));
        $perPage = min(max($request->integer('per_page', 40), 1), 200);
        $total = $query->count();

        return new JsonResponse([
            'items' => MediaResource::collection(
                $query->orderByPivot('added_at', 'desc')->orderBy('media.media_id', 'desc')->forPage($page, $perPage)->get(),
            ),
            'total_pages' => max(1, (int) ceil($total / $perPage)),
            'current_page' => $page,
            'next_cursor' => null,
        ]);
    }

    /**
     * PUT /lists/{list_id}/media/{media_id} — Add media to a list. Adding it
     * again changes nothing.
     */
    #[OAS\Operation\Put(
        path: '/lists/{list_id}/media/{media_id}',
        operationId: 'addToFavoriteList',
        summary: 'Add media to a list',
        tags: ['Lists'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'list_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
            new OAS\Parameter(name: 'media_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(response: 204, description: 'Added'),
            new OAS\Response(response: 404, description: 'ListNotFound, MediaNotFound', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function add(Request $request, FavoriteList $list, Media $media): Response
    {
        self::own($request, $list)->media()->syncWithoutDetaching([$media->media_id]);

        return response()->noContent();
    }

    /**
     * POST /lists/{list_id}/media — Add many media at once. Body:
     * { media_ids: int[] } (at most 2000). Ids of media that don't exist are
     * skipped. Answers { added }, how many weren't in the list already.
     */
    #[OAS\Operation\Post(
        path: '/lists/{list_id}/media',
        operationId: 'addManyToFavoriteList',
        summary: 'Add many media to a list',
        tags: ['Lists'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [new OAS\Parameter(name: 'list_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(required: ['media_ids'], properties: [
                new OAS\Property(property: 'media_ids', schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(type: 'integer'), maxItems: 2000)),
            ]),
        ),
        responses: [
            new OAS\Response(response: 200, description: 'How many were added', content: new OAS\MediaType\Json(properties: [new OAS\Property(property: 'added', schema: new OAS\Schema(type: 'integer'))])),
            new OAS\Response(response: 400, description: 'InvalidInput', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 404, description: 'ListNotFound', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function addMany(Request $request, FavoriteList $list): JsonResponse
    {
        self::own($request, $list);
        $ids = $request->input('media_ids');
        if (!is_array($ids) || count($ids) > self::MAX_ADD) {
            throw new ApiException('InvalidInput', 400, 'media_ids is a list of at most 2000 media IDs.');
        }

        $existing = Media::query()->whereKey(array_values(array_filter(array_map(intval(...), $ids), static fn(int $id): bool => $id > 0)))->pluck('media_id')->all();
        $attached = $list->media()->syncWithoutDetaching($existing)['attached'];

        return new JsonResponse(['added' => count($attached)]);
    }

    /**
     * DELETE /lists/{list_id}/media/{media_id} — Take media out of a list.
     */
    #[OAS\Operation\Delete(
        path: '/lists/{list_id}/media/{media_id}',
        operationId: 'removeFromFavoriteList',
        summary: 'Take media out of a list',
        tags: ['Lists'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'list_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
            new OAS\Parameter(name: 'media_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(response: 204, description: 'Removed'),
            new OAS\Response(response: 404, description: 'ListNotFound, MediaNotFound', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function remove(Request $request, FavoriteList $list, Media $media): Response
    {
        self::own($request, $list)->media()->detach($media->media_id);

        return response()->noContent();
    }

    /**
     * GET /media/{media_id}/lists — Which of the account's lists hold this
     * media, by id.
     */
    #[OAS\Operation\Get(
        path: '/media/{media_id}/lists',
        operationId: 'listsHoldingMedia',
        summary: 'Your lists that hold this media',
        tags: ['Lists'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [new OAS\Parameter(name: 'media_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        responses: [
            new OAS\Response(response: 200, description: 'The list ids', content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(type: 'integer'))),
            new OAS\Response(response: 404, description: 'MediaNotFound', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function holding(Request $request, Media $media): JsonResponse
    {
        $ids = self::user($request)->favoriteLists()
            ->whereHas('media', static fn($query) => $query->whereKey($media->media_id))
            ->pluck('list_id')
            ->all();

        return new JsonResponse(array_map(intval(...), $ids));
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new ApiException('Unauthorized', 401, 'Authentication is required. Please log in and try again.');
    }

    /** The list, if it is the signed-in account's; someone else's is as missing as a deleted one. */
    private static function own(Request $request, FavoriteList $list): FavoriteList
    {
        return $list->user_id === self::user($request)->id
            ? $list
            : throw new ApiException('ListNotFound', 404, 'The list could not be found.');
    }
}
