<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SystemController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes (prefixed /api)
|--------------------------------------------------------------------------
|
| Every route answers as its recordings in backend-old/tests/Fixtures/contract/
| say the Slim app did (tests/Feature/Contract/ContractTest.php replays them).
| The api group adds the 120-a-minute rate limiter; reads are public, and
| writes need auth:sanctum except where the contract makes them public.
| The URIs and parameter names are the endpoint inventory's in
| docs/laravel-migration.md, which the replay uses to tell what is ported.
|
*/

Route::get('/version', [SystemController::class, 'version']);
Route::get('/openapi.json', [SystemController::class, 'openApi']);
Route::get('/docs', [SystemController::class, 'docs']);

Route::post('/auth/login', [AuthController::class, 'login']);
