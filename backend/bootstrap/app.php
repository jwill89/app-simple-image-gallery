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
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;

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

        // There is no login page to send a guest to: a request that isn't
        // signed in is answered 401, whatever it accepts.
        $middleware->redirectGuestsTo(null);

        // The API keeps a session, whose encrypted, HttpOnly cookie says which
        // account a browser signed in as (config/session.php). AuthenticateSession
        // signs a session out once its account's password has changed since, as
        // it does a remember cookie. Request forgery is refused by the browser's
        // own word: a write the browser doesn't mark Sec-Fetch-Site: same-origin
        // is a 403, so no CSRF token is needed (VerifyOrigin checks its origin too).
        $middleware->api(prepend: [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            AuthenticateSession::class,
            PreventRequestForgery::class,
            'throttle:api',
        ]);
        $middleware->preventRequestForgery(originOnly: true);
        // An admin account, after auth has found one (routes/api.php).
        $middleware->alias(['admin' => EnsureAdmin::class]);
        // The only web route is the SPA shell, which keeps no session and sets
        // no cookies; only the API signs anyone in.
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
