<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DanbooruCategoryMappingController;
use App\Http\Controllers\Api\DanbooruTagMappingController;
use App\Http\Controllers\Api\DuplicateController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\MediaTagController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\TagCategoryController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\TagImplicationController;
use App\Http\Controllers\Api\UploadController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes (prefixed /api)
|--------------------------------------------------------------------------
|
| Every route answers as its recordings in tests/Fixtures/contract/ say the
| Slim app did (tests/Feature/Contract/ContractTest.php replays them).
| The api group adds the 120-a-minute rate limiter. The URIs and parameter
| names are the endpoint inventory's in docs/laravel-migration.md, which the
| replay uses to tell what is ported.
|
| Ids are not constrained to digits: an endpoint answers a malformed id with
| its own 400. So a route with a fixed segment (/media/random) is registered
| before the one with a parameter in its place (/media/{media_id}).
|
*/

// Public: every read, login, and the three writes anyone may make.
Route::get('/version', [SystemController::class, 'version']);
Route::get('/openapi.json', [SystemController::class, 'openApi']);
Route::get('/docs', [SystemController::class, 'docs']);

Route::post('/auth/login', [AuthController::class, 'login']);

Route::get('/media/page/{page}/{per_page?}', [MediaController::class, 'index']);
Route::get('/media/untagged/{page}/{per_page?}', [MediaController::class, 'untagged']);
Route::get('/media/with-tags/{tag_list}/{page}/{per_page?}', [MediaController::class, 'search']);
Route::get('/media/random', [MediaController::class, 'random']);
Route::get('/media/count', [MediaController::class, 'count']);
// A read that is a POST only to carry a long list of ids.
Route::post('/media/by-ids', [MediaController::class, 'byIds']);
Route::get('/media/{media_id}', [MediaController::class, 'show']);

Route::get('/media/{media_id}/tags', [MediaTagController::class, 'index']);
// Anyone may tag.
Route::patch('/media/{media_id}/tags', [MediaTagController::class, 'store']);
Route::delete('/media/{media_id}/tags/{tag_id}', [MediaTagController::class, 'destroy']);

Route::get('/tags', [TagController::class, 'index']);
Route::get('/tags/display', [TagController::class, 'display']);
Route::get('/tags/{tag_id}', [TagController::class, 'show']);

Route::get('/tag-categories', [TagCategoryController::class, 'index']);

Route::get('/tag-implications', [TagImplicationController::class, 'index']);

Route::get('/danbooru/category-mappings', [DanbooruCategoryMappingController::class, 'index']);
Route::get('/danbooru/tag-mappings', [DanbooruTagMappingController::class, 'index']);

Route::get('/duplicates/report', [DuplicateController::class, 'report']);

// Every other write needs the admin's token.
Route::middleware('auth:sanctum')->group(static function (): void {
    Route::post('/media', [UploadController::class, 'store']);
    Route::post('/media/bulk-delete', [MediaController::class, 'bulkDestroy']);
    Route::delete('/media/{media_id}', [MediaController::class, 'destroy']);
    Route::post('/media/{media_id}/danbooru-tags', [MediaTagController::class, 'importFromDanbooru']);

    Route::post('/tags', [TagController::class, 'store']);
    Route::put('/tags/{tag_id}', [TagController::class, 'update']);
    Route::delete('/tags/{tag_id}', [TagController::class, 'destroy']);
    Route::delete('/tags/{tag_id}/migrate-to/{target_tag_id}', [TagController::class, 'destroy']);
    Route::post('/tags/{tag_id}/migrate', [TagController::class, 'migrate']);

    Route::post('/tag-categories', [TagCategoryController::class, 'store']);
    Route::put('/tag-categories/{category_id}', [TagCategoryController::class, 'update']);
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
