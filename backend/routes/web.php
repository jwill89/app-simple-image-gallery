<?php

declare(strict_types=1);

use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| Apache serves the built assets and media files under public/ itself, so
| everything that reaches the app outside /api is a page of the SPA: the
| shell answers it, and the SPA routes it in the browser. An /api path no API
| route matches is left alone, so it stays a JSON 404 or 405.
|
| The web middleware group is empty (bootstrap/app.php): the shell needs no
| session, cookie or CSRF token.
|
*/

Route::fallback(SpaController::class)->where('fallbackPlaceholder', '(?!api(?:/|$)).*');
