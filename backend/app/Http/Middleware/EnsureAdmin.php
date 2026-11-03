<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets an admin account through; any other signed-in account is 403
 * Forbidden. Runs after auth:sanctum, which has already turned away a
 * request without a valid token (401).
 */
final class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user instanceof User || !$user->is_admin) {
            throw new ApiException('Forbidden', 403, 'This needs an admin account.');
        }

        return $next($request);
    }
}
