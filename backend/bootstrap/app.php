<?php

declare(strict_types=1);

use App\Exceptions\ApiException;
use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VerifyOrigin;
use App\Support\Cloudflare;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
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
        // The visitor's address, not Cloudflare's (App\Support\Cloudflare).
        $middleware->trustProxies(at: Cloudflare::RANGES, headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);

        // There is no login page to send a guest to: a request without a valid
        // token is answered 401, whatever it accepts.
        $middleware->redirectGuestsTo(null);

        $middleware->api(prepend: ['throttle:api']);
        // An admin account, after auth:sanctum has found one (routes/api.php).
        $middleware->alias(['admin' => EnsureAdmin::class]);
        // The only web route is the SPA shell, which keeps no session and sets
        // no cookies; the API authenticates with bearer tokens.
        $middleware->group('web', []);
        // The rate limiter counts every request, signed in or not, so it runs
        // before authentication rather than after, Laravel's default.
        $middleware->priority([
            ThrottleRequests::class,
            AuthenticatesRequests::class,
            // Before the bindings: an account that isn't an admin is told so,
            // not whether the record it named exists.
            EnsureAdmin::class,
            SubstituteBindings::class,
            Authorize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(new ApiExceptionRenderer());
        // A 4xx is the client's mistake, answered in full; only a 5xx is the
        // server's problem and worth a log entry.
        $exceptions->dontReportWhen(static fn(Throwable $e): bool => $e instanceof ApiException && $e->status < 500);
    })->create();
