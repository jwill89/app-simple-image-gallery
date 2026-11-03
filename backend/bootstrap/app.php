<?php

declare(strict_types=1);

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VerifyOrigin;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(SecurityHeaders::class);
        // After HandleCors, which answers preflights before anything checks them.
        $middleware->append(VerifyOrigin::class);
        // The endpoints trim and sanitize what they need themselves; a password
        // or a tag name arrives exactly as sent.
        $middleware->remove([TrimStrings::class, ConvertEmptyStringsToNull::class]);

        $middleware->api(prepend: ['throttle:api']);
        // The rate limiter counts every request, signed in or not, so it runs
        // before authentication rather than after, Laravel's default.
        $middleware->priority([
            ThrottleRequests::class,
            AuthenticatesRequests::class,
            SubstituteBindings::class,
            Authorize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(new ApiExceptionRenderer());
    })->create();
