<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DanbooruCategoryMappingController;
use App\Http\Controllers\Api\DanbooruTagMappingController;
use App\Http\Controllers\Api\DuplicateController;
use App\Http\Controllers\Api\FavoriteListController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\MediaTagController;
use App\Http\Controllers\Api\SavedSearchController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\TagCategoryController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\TagImplicationController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes (prefixed /api)
|--------------------------------------------------------------------------
|
| API 6. The recordings in tests/Fixtures/contract/ say how the Slim app
| answered API 4; tests/Feature/Contract/ContractTest.php replays them, and
| divergences.php lists what API 5 and 6 answer differently. The api group
| adds the 120-a-minute rate limiter.
|
| A path id is digits and names a record that exists, or the request is a 404
| (AppServiceProvider binds each {…_id} to its model).
|
*/

// Public reads. Each answers with an ETag and Cache-Control: no-cache, so a
// browser keeps the body and asks again with If-None-Match: an unchanged
// answer is a 304 with no body. A signed-in account's token, when sent, still
// counts: its blocked tags leave the media listings (App\Support\Viewer).
Route::middleware('cache.headers:no_cache;private;etag')->group(static function (): void {
    Route::get('/version', [SystemController::class, 'version']);

    Route::get('/media', [MediaController::class, 'feed']);
    Route::get('/media/page/{page}/{per_page?}', [MediaController::class, 'index']);
    Route::get('/media/untagged/{page}/{per_page?}', [MediaController::class, 'untagged']);
    Route::get('/media/with-tags/{tag_list}/{page}/{per_page?}', [MediaController::class, 'search']);
    Route::get('/media/count', [MediaController::class, 'count']);
    Route::get('/media/tag-imports', [MediaTagController::class, 'importStatus']);
    Route::get('/media/{media_id}', [MediaController::class, 'show']);
    Route::get('/media/{media_id}/tags', [MediaTagController::class, 'index']);

    Route::get('/tags', [TagController::class, 'index']);
    Route::get('/tags/display', [TagController::class, 'display']);
    Route::get('/tags/{tag_id}', [TagController::class, 'show']);
    Route::get('/tag-categories', [TagCategoryController::class, 'index']);
    Route::get('/tag-implications', [TagImplicationController::class, 'index']);
    Route::get('/danbooru/category-mappings', [DanbooruCategoryMappingController::class, 'index']);
    Route::get('/danbooru/tag-mappings', [DanbooruTagMappingController::class, 'index']);
    Route::get('/duplicates/report', [DuplicateController::class, 'report']);
});

// Reads with caching of their own (the spec, the docs page), or none at all.
Route::get('/openapi.json', [SystemController::class, 'openApi']);
Route::get('/docs', [SystemController::class, 'docs']);
Route::get('/media/random', [MediaController::class, 'random']);

Route::get('/auth/config', [AuthController::class, 'config']);
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

// A read that is a POST only to carry a long list of ids.
Route::post('/media/by-ids', [MediaController::class, 'byIds']);

// Anyone may tag.
Route::patch('/media/{media_id}/tags', [MediaTagController::class, 'store']);
Route::delete('/media/{media_id}/tags/{tag_id}', [MediaTagController::class, 'destroy']);

// An account's own things: any signed-in account.
Route::middleware('auth:sanctum')->group(static function (): void {
    Route::delete('/auth/token', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::patch('/account/password', [AccountController::class, 'changePassword']);
    Route::delete('/account', [AccountController::class, 'destroy']);
    Route::get('/account/blocked-tags', [AccountController::class, 'blockedTags']);
    Route::put('/account/blocked-tags/{tag_id}', [AccountController::class, 'blockTag']);
    Route::delete('/account/blocked-tags/{tag_id}', [AccountController::class, 'unblockTag']);

    Route::get('/lists', [FavoriteListController::class, 'index']);
    Route::post('/lists', [FavoriteListController::class, 'store']);
    Route::patch('/lists/{list_id}', [FavoriteListController::class, 'update']);
    Route::delete('/lists/{list_id}', [FavoriteListController::class, 'destroy']);
    Route::get('/lists/{list_id}/ids', [FavoriteListController::class, 'ids']);
    Route::get('/lists/{list_id}/media', [FavoriteListController::class, 'media']);
    Route::post('/lists/{list_id}/media', [FavoriteListController::class, 'addMany']);
    Route::put('/lists/{list_id}/media/{media_id}', [FavoriteListController::class, 'add']);
    Route::delete('/lists/{list_id}/media/{media_id}', [FavoriteListController::class, 'remove']);
    Route::get('/media/{media_id}/lists', [FavoriteListController::class, 'holding']);

    Route::get('/saved-searches', [SavedSearchController::class, 'index']);
    Route::post('/saved-searches', [SavedSearchController::class, 'store']);
    Route::patch('/saved-searches/{search_id}', [SavedSearchController::class, 'update']);
    Route::delete('/saved-searches/{search_id}', [SavedSearchController::class, 'destroy']);
});

// Every other write needs an admin account.
Route::middleware(['auth:sanctum', 'admin'])->group(static function (): void {
    Route::get('/users', [UserController::class, 'index']);
    Route::patch('/users/{user_id}', [UserController::class, 'update']);
    Route::delete('/users/{user_id}', [UserController::class, 'destroy']);

    Route::post('/media', [UploadController::class, 'store']);
    Route::post('/media/bulk-delete', [MediaController::class, 'bulkDestroy']);
    Route::delete('/media/{media_id}', [MediaController::class, 'destroy']);
    Route::post('/media/{media_id}/danbooru-tags', [MediaTagController::class, 'importFromDanbooru']);

    Route::post('/tags', [TagController::class, 'store']);
    Route::match(['put', 'patch'], '/tags/{tag_id}', [TagController::class, 'update']);
    Route::delete('/tags/{tag_id}', [TagController::class, 'destroy']);
    Route::delete('/tags/{tag_id}/migrate-to/{target_tag_id}', [TagController::class, 'destroy']);
    Route::post('/tags/{tag_id}/migrate', [TagController::class, 'migrate']);

    Route::post('/tag-categories', [TagCategoryController::class, 'store']);
    Route::match(['put', 'patch'], '/tag-categories/{category_id}', [TagCategoryController::class, 'update']);
    Route::delete('/tag-categories/{category_id}', [TagCategoryController::class, 'destroy']);

    Route::post('/tag-implications', [TagImplicationController::class, 'store']);
    Route::delete('/tag-implications/{tag_id}/{implied_tag_id}', [TagImplicationController::class, 'destroy']);

    Route::post('/danbooru/category-mappings', [DanbooruCategoryMappingController::class, 'store']);
    Route::delete('/danbooru/category-mappings/{danbooru_category_id}', [DanbooruCategoryMappingController::class, 'destroy']);
    Route::post('/danbooru/tag-mappings', [DanbooruTagMappingController::class, 'store']);
    Route::put('/danbooru/tag-mappings/{id}', [DanbooruTagMappingController::class, 'update']);
    Route::delete('/danbooru/tag-mappings/{id}', [DanbooruTagMappingController::class, 'destroy']);

    Route::post('/duplicates/scan', [DuplicateController::class, 'scan']);
    Route::post('/duplicates/dismissals', [DuplicateController::class, 'dismiss']);
});
