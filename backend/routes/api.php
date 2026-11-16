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
use App\Http\Controllers\Api\PasskeyController;
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
| The api group keeps the session (bootstrap/app.php) and adds the
| 120-a-minute rate limiter.
|
| A path id is digits and names a record that exists, or the request is a 404
| (AppServiceProvider binds each {…Id} to its model). A trailing slash is
| ignored: /api/tags/ answers as /api/tags.
|
*/

// A random pick is never the same twice, so it has no ETag. It comes before
// /media/{mediaId}, whose place it shares.
Route::get('/media/random', [MediaController::class, 'random']);

// Public reads. Each answers with an ETag and Cache-Control: no-cache, so a
// browser keeps the body and asks again with If-None-Match: an unchanged
// answer is a 304 with no body. A signed-in browser's session still counts:
// its account's blocked tags leave the media listings (App\Support\Viewer).
Route::middleware('cache.headers:no_cache;private;etag')->group(static function (): void {
    Route::get('/version', [SystemController::class, 'version']);

    Route::get('/media', [MediaController::class, 'index']);
    Route::get('/media/count', [MediaController::class, 'count']);
    Route::get('/media/tag-imports', [MediaTagController::class, 'importStatus']);
    Route::get('/media/{mediaId}', [MediaController::class, 'show']);
    Route::get('/media/{mediaId}/tags', [MediaTagController::class, 'index']);

    Route::get('/tags', [TagController::class, 'index']);
    Route::get('/tags/{tagId}', [TagController::class, 'show']);
    Route::get('/tag-categories', [TagCategoryController::class, 'index']);
    Route::get('/tag-implications', [TagImplicationController::class, 'index']);
    Route::get('/danbooru/category-mappings', [DanbooruCategoryMappingController::class, 'index']);
    Route::get('/danbooru/tag-mappings', [DanbooruTagMappingController::class, 'index']);
    Route::get('/duplicates/report', [DuplicateController::class, 'report']);
});

// Reads with caching of their own (the spec, the docs page), or none at all.
Route::get('/openapi.json', [SystemController::class, 'openApi']);
Route::get('/docs', [SystemController::class, 'docs']);

Route::get('/auth/config', [AuthController::class, 'config']);
Route::get('/auth/session', [AuthController::class, 'session']);
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/passkey/options', [PasskeyController::class, 'signInOptions']);
Route::post('/auth/passkey', [PasskeyController::class, 'signIn']);

// A read that is a POST only to carry a long list of ids.
Route::post('/media/by-ids', [MediaController::class, 'byIds']);

// Anyone may tag.
Route::patch('/media/{mediaId}/tags', [MediaTagController::class, 'store']);
Route::delete('/media/{mediaId}/tags/{tagId}', [MediaTagController::class, 'destroy']);

// An account's own things: any signed-in account.
Route::middleware('auth')->group(static function (): void {
    Route::delete('/auth/session', [AuthController::class, 'logout']);

    Route::patch('/account/password', [AccountController::class, 'changePassword']);
    Route::delete('/account', [AccountController::class, 'destroy']);
    Route::get('/account/blocked-tags', [AccountController::class, 'blockedTags']);
    Route::put('/account/blocked-tags/{tagId}', [AccountController::class, 'blockTag']);
    Route::delete('/account/blocked-tags/{tagId}', [AccountController::class, 'unblockTag']);
    Route::get('/account/passkeys', [PasskeyController::class, 'index']);
    Route::post('/account/passkeys/options', [PasskeyController::class, 'addOptions']);
    Route::post('/account/passkeys', [PasskeyController::class, 'store']);
    Route::patch('/account/passkeys/{passkeyId}', [PasskeyController::class, 'update']);
    Route::delete('/account/passkeys/{passkeyId}', [PasskeyController::class, 'destroy']);

    Route::get('/lists', [FavoriteListController::class, 'index']);
    Route::post('/lists', [FavoriteListController::class, 'store']);
    Route::patch('/lists/{listId}', [FavoriteListController::class, 'update']);
    Route::delete('/lists/{listId}', [FavoriteListController::class, 'destroy']);
    Route::get('/lists/{listId}/ids', [FavoriteListController::class, 'ids']);
    Route::get('/lists/{listId}/media', [FavoriteListController::class, 'media']);
    Route::post('/lists/{listId}/media', [FavoriteListController::class, 'addMany']);
    Route::put('/lists/{listId}/media/{mediaId}', [FavoriteListController::class, 'add']);
    Route::delete('/lists/{listId}/media/{mediaId}', [FavoriteListController::class, 'remove']);
    Route::get('/media/{mediaId}/lists', [FavoriteListController::class, 'holding']);

    Route::get('/saved-searches', [SavedSearchController::class, 'index']);
    Route::post('/saved-searches', [SavedSearchController::class, 'store']);
    Route::patch('/saved-searches/{searchId}', [SavedSearchController::class, 'update']);
    Route::delete('/saved-searches/{searchId}', [SavedSearchController::class, 'destroy']);
});

// Every other write needs an admin account.
Route::middleware(['auth', 'admin'])->group(static function (): void {
    Route::get('/users', [UserController::class, 'index']);
    Route::patch('/users/{userId}', [UserController::class, 'update']);
    Route::delete('/users/{userId}', [UserController::class, 'destroy']);

    Route::post('/media', [UploadController::class, 'store']);
    Route::post('/media/bulk-delete', [MediaController::class, 'bulkDestroy']);
    Route::delete('/media/{mediaId}', [MediaController::class, 'destroy']);
    Route::post('/media/{mediaId}/danbooru-tags', [MediaTagController::class, 'importFromDanbooru']);

    Route::post('/tags', [TagController::class, 'store']);
    Route::patch('/tags/{tagId}', [TagController::class, 'update']);
    Route::delete('/tags/{tagId}', [TagController::class, 'destroy']);
    Route::delete('/tags/{tagId}/migrate-to/{targetTagId}', [TagController::class, 'destroy']);
    Route::post('/tags/{tagId}/migrate', [TagController::class, 'migrate']);

    Route::post('/tag-categories', [TagCategoryController::class, 'store']);
    Route::patch('/tag-categories/{categoryId}', [TagCategoryController::class, 'update']);
    Route::delete('/tag-categories/{categoryId}', [TagCategoryController::class, 'destroy']);

    Route::post('/tag-implications', [TagImplicationController::class, 'store']);
    Route::delete('/tag-implications/{tagId}/{impliedTagId}', [TagImplicationController::class, 'destroy']);

    Route::post('/danbooru/category-mappings', [DanbooruCategoryMappingController::class, 'store']);
    Route::delete('/danbooru/category-mappings/{danbooruCategoryId}', [DanbooruCategoryMappingController::class, 'destroy']);
    Route::post('/danbooru/tag-mappings', [DanbooruTagMappingController::class, 'store']);
    Route::patch('/danbooru/tag-mappings/{mappingId}', [DanbooruTagMappingController::class, 'update']);
    Route::delete('/danbooru/tag-mappings/{mappingId}', [DanbooruTagMappingController::class, 'destroy']);

    Route::post('/duplicates/scan', [DuplicateController::class, 'scan']);
    Route::post('/duplicates/dismissals', [DuplicateController::class, 'dismiss']);
});
