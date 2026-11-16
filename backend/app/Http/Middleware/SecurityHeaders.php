<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ContentSecurityPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every response must be taken as the content type it says it is, tells
 * another site no more than that a visitor came from the gallery, and may not
 * be framed by another site. A page sets its own Content-Security-Policy (the
 * SPA shell, the API reference); anything else gets the policy that allows
 * nothing (ContentSecurityPolicy::NONE).
 */
final class SecurityHeaders
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        if (!ContentSecurityPolicy::isSet($response)) {
            ContentSecurityPolicy::apply($response, ContentSecurityPolicy::NONE);
        }

        return $response;
    }
}
