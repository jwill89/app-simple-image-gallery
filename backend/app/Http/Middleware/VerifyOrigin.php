<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * The CSRF check for /api: a POST, PUT, PATCH or DELETE is refused (403
 * ForbiddenOrigin) unless its Origin, or failing that the origin of its
 * Referer, is in config('gallery.allowed_origins'). A write with neither
 * header is refused too: browsers always send one, so its absence means a
 * client working around CORS, such as curl.
 *
 * Global, ahead of the rate limiter and authentication, so a refused request
 * is neither counted nor told whether its token was good.
 */
final class VerifyOrigin
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->is('api', 'api/*') || !in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $origin = (string) $request->headers->get('Origin', '');
        $referer = (string) $request->headers->get('Referer', '');
        $claimed = match (true) {
            $origin !== '' => $origin,
            $referer !== '' => self::originOf($referer),
            default => null,
        };

        if ($claimed === null || !in_array($claimed, config()->array('gallery.allowed_origins'), true)) {
            Log::warning('CSRF rejected', ['origin' => $origin, 'referer' => $referer]);

            return ApiException::response('ForbiddenOrigin', 403, 'The request origin is not allowed.');
        }

        return $next($request);
    }

    /** scheme://host[:port] of a URL, as a browser would send it in Origin. */
    private static function originOf(string $url): string
    {
        $port = parse_url($url, PHP_URL_PORT);

        return parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST) . (is_int($port) ? ":{$port}" : '');
    }
}
