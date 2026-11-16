<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\FavoriteListRequest;
use App\Http\Requests\PageRequest;
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
use App\OpenApi\ProblemJson;
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
        requestBody: new OAS\RequestBody(required: true, content: new OAS\MediaType\Json(ref: '#/components/schemas/FavoriteListBody')),
        responses: [
            new OAS\Response(response: 201, description: 'The list', content: new OAS\MediaType\Json(ref: '#/components/schemas/FavoriteList')),
            new OAS\Response(response: 409, description: 'ListExists', content: new ProblemJson()),
            new OAS\Response(response: 422, description: 'InvalidListName', content: new ProblemJson()),
        ],
    )]
    public function store(FavoriteListRequest $request): JsonResponse
    {
        $list = self::user($request)->favoriteLists()->create(['name' => $request->string('name')->toString()]);

        return new FavoriteListResource($list->loadCount('media'))->response()->setStatusCode(201);
    }

    /**
     * PATCH /lists/{listId} — Rename a list. Body: { name }.
     */
    #[OAS\Operation\Patch(
        path: '/lists/{listId}',
        operationId: 'renameFavoriteList',
        summary: 'Rename a list',
        tags: ['Lists'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [new OAS\Parameter(name: 'listId', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        requestBody: new OAS\RequestBody(required: true, content: new OAS\MediaType\Json(ref: '#/components/schemas/FavoriteListBody')),
        responses: [
            new OAS\Response(response: 200, description: 'The list', content: new OAS\MediaType\Json(ref: '#/components/schemas/FavoriteList')),
            new OAS\Response(response: 404, description: 'ListNotFound', content: new ProblemJson()),
            new OAS\Response(response: 409, description: 'ListExists', content: new ProblemJson()),
            new OAS\Response(response: 422, description: 'InvalidListName', content: new ProblemJson()),
        ],
    )]
    public function update(FavoriteListRequest $request, FavoriteList $list): FavoriteListResource
    {
        self::own($request, $list)->update(['name' => $request->string('name')->toString()]);

        return new FavoriteListResource($list->loadCount('media'));
    }

    /**
     * DELETE /lists/{listId} — Delete a list (not its media). The default
     * list can't be.
     */
    #[OAS\Operation\Delete(
        path: '/lists/{listId}',
        operationId: 'deleteFavoriteList',
        summary: 'Delete a list',
        tags: ['Lists'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [new OAS\Parameter(name: 'listId', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        responses: [
            new OAS\Response(response: 204, description: 'Deleted'),
            new OAS\Response(response: 404, description: 'ListNotFound', content: new ProblemJson()),
            new OAS\Response(response: 409, description: 'DefaultList', content: new ProblemJson()),
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
     * GET /lists/{listId}/ids — Every media id in a list, for marking cards.
     */
    #[OAS\Operation\Get(
        path: '/lists/{listId}/ids',
        operationId: 'favoriteListIds',
        summary: 'The media ids in a list',
        tags: ['Lists'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [new OAS\Parameter(name: 'listId', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        responses: [
            new OAS\Response(response: 200, description: 'The ids', content: new OAS\MediaType\Json(ref: '#/components/schemas/MediaIdList')),
            new OAS\Response(response: 404, description: 'ListNotFound', content: new ProblemJson()),
        ],
    )]
    public function ids(Request $request, FavoriteList $list): JsonResponse
    {
        return new JsonResponse(array_map(intval(...), self::own($request, $list)->media()->pluck('media.media_id')->all()));
    }

    /**
     * GET /lists/{listId}/media?limit&offset — A page of a list, the most
     * recently added first, with the list's size in X-Total-Count. Media
     * carrying a tag the account blocks is left out, as from every listing.
     */
    #[OAS\Operation\Get(
        path: '/lists/{listId}/media',
        operationId: 'favoriteListMedia',
        summary: 'A page of a list',
        tags: ['Lists'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'listId', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
            new OAS\Parameter(name: 'limit', in: 'query', required: false, schema: new OAS\Schema(type: 'integer', default: 40, maximum: 100, minimum: 1)),
            new OAS\Parameter(name: 'offset', in: 'query', required: false, schema: new OAS\Schema(type: 'integer', default: 0, minimum: 0)),
        ],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'A page of the list',
                headers: [new OAS\Header(header: 'X-Total-Count', description: 'How many items the list holds that the account may see.', schema: new OAS\Schema(type: 'integer'))],
                content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/Media')),
            ),
            new OAS\Response(response: 404, description: 'ListNotFound', content: new ProblemJson()),
            new OAS\Response(response: 422, description: 'InvalidInput', content: new ProblemJson()),
        ],
    )]
    public function media(PageRequest $request, FavoriteList $list): JsonResponse
    {
        $query = self::own($request, $list)->media()->hidingTags(Viewer::hiddenTags($request));
        $total = $query->count();
        $items = $query->orderByPivot('added_at', 'desc')->orderBy('media.media_id', 'desc')->offset($request->offset())->limit($request->limit())->get();

        return MediaResource::collection($items)->response($request)->header('X-Total-Count', (string) $total);
    }

    /**
     * PUT /lists/{listId}/media/{mediaId} — Add media to a list. Adding it
     * again changes nothing.
     */
    #[OAS\Operation\Put(
        path: '/lists/{listId}/media/{mediaId}',
        operationId: 'addToFavoriteList',
        summary: 'Add media to a list',
        tags: ['Lists'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'listId', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
            new OAS\Parameter(name: 'mediaId', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(response: 204, description: 'Added'),
            new OAS\Response(response: 404, description: 'ListNotFound, MediaNotFound', content: new ProblemJson()),
        ],
    )]
    public function add(Request $request, FavoriteList $list, Media $media): Response
    {
        self::own($request, $list)->media()->syncWithoutDetaching([$media->media_id]);

        return response()->noContent();
    }

    /**
     * POST /lists/{listId}/media — Add many media at once. Body:
     * { mediaIds: int[] } (at most 2000). Ids of media that don't exist are
     * skipped. Answers { added }, how many weren't in the list already.
     */
    #[OAS\Operation\Post(
        path: '/lists/{listId}/media',
        operationId: 'addManyToFavoriteList',
        summary: 'Add many media to a list',
        tags: ['Lists'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [new OAS\Parameter(name: 'listId', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        requestBody: new OAS\RequestBody(required: true, content: new OAS\MediaType\Json(ref: '#/components/schemas/ListItemsBody')),
        responses: [
            new OAS\Response(response: 200, description: 'How many were added', content: new OAS\MediaType\Json(ref: '#/components/schemas/AddedCount')),
            new OAS\Response(response: 404, description: 'ListNotFound', content: new ProblemJson()),
            new OAS\Response(response: 422, description: 'InvalidInput', content: new ProblemJson()),
        ],
    )]
    public function addMany(Request $request, FavoriteList $list): JsonResponse
    {
        self::own($request, $list);
        $ids = $request->input('mediaIds');
        if (!is_array($ids) || count($ids) > self::MAX_ADD) {
            $message = 'mediaIds is a list of at most 2000 media IDs.';

            throw new ApiException('InvalidInput', 422, $message, [], [['message' => $message, 'location' => 'body.mediaIds']]);
        }

        $existing = Media::query()->whereKey(array_values(array_filter(array_map(intval(...), $ids), static fn(int $id): bool => $id > 0)))->pluck('media_id')->all();
        $attached = $list->media()->syncWithoutDetaching($existing)['attached'];

        return new JsonResponse(['added' => count($attached)]);
    }

    /**
     * DELETE /lists/{listId}/media/{mediaId} — Take media out of a list.
     */
    #[OAS\Operation\Delete(
        path: '/lists/{listId}/media/{mediaId}',
        operationId: 'removeFromFavoriteList',
        summary: 'Take media out of a list',
        tags: ['Lists'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'listId', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
            new OAS\Parameter(name: 'mediaId', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(response: 204, description: 'Removed'),
            new OAS\Response(response: 404, description: 'ListNotFound, MediaNotFound', content: new ProblemJson()),
        ],
    )]
    public function remove(Request $request, FavoriteList $list, Media $media): Response
    {
        self::own($request, $list)->media()->detach($media->media_id);

        return response()->noContent();
    }

    /**
     * GET /media/{mediaId}/lists — Which of the account's lists hold this
     * media, by id.
     */
    #[OAS\Operation\Get(
        path: '/media/{mediaId}/lists',
        operationId: 'listsHoldingMedia',
        summary: 'Your lists that hold this media',
        tags: ['Lists'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [new OAS\Parameter(name: 'mediaId', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        responses: [
            new OAS\Response(response: 200, description: 'The list ids', content: new OAS\MediaType\Json(ref: '#/components/schemas/ListIdList')),
            new OAS\Response(response: 404, description: 'MediaNotFound', content: new ProblemJson()),
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
