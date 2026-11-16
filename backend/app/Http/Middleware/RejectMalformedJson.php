<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A request that says its body is JSON, and whose body isn't, is 400
 * MalformedJson: Laravel would read it as an empty body and answer as though
 * every field were missing.
 */
final class RejectMalformedJson
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $body = $request->getContent();
        if ($request->isJson() && trim($body) !== '' && !json_validate($body)) {
            throw new ApiException('MalformedJson', 400, 'The request body is not valid JSON.');
        }

        return $next($request);
    }
}
