<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

final class AuthController extends Controller
{
    /** Login attempts allowed per IP in LOGIN_WINDOW seconds, successful ones included. */
    private const int LOGIN_ATTEMPTS = 10;

    private const int LOGIN_WINDOW = 300;

    /**
     * POST /auth/login: exchange the admin password for a bearer token, valid
     * for 24 hours (sanctum.expiration).
     *
     * Throttled to 10 attempts per 5 minutes per IP, on top of the API's
     * limit. That is checked here rather than by a second throttle middleware,
     * which would report its own, smaller X-RateLimit-Remaining: the contract's
     * header is the API limit's.
     *
     * The token is 64 hex characters, as the Slim app issued: stored hashed by
     * Sanctum, and found by that hash since it carries no "<id>|" prefix.
     */
    public function login(Request $request): JsonResponse
    {
        $throttleKey = 'login:' . $request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, self::LOGIN_ATTEMPTS)) {
            Log::warning('Login rate limit exceeded', ['ip' => $request->ip()]);

            throw new ApiException('TooManyAttempts', 429, 'Too many login attempts. Please wait a few minutes and try again.', [
                'Retry-After' => (string) RateLimiter::availableIn($throttleKey),
            ]);
        }
        RateLimiter::hit($throttleKey, self::LOGIN_WINDOW);

        $adminPassword = config()->string('gallery.admin_password');

        // Refused outright while no password is configured, so an empty one can
        // never grant access.
        if ($adminPassword === '') {
            Log::error('Login attempted but GALLERY_ADMIN_PASSWORD is not configured', ['ip' => $request->ip()]);

            throw new ApiException('AdminNotConfigured', 503, 'Admin access is not configured on the server.');
        }

        $password = $request->input('password');
        if (!is_string($password) || !hash_equals($adminPassword, $password)) {
            Log::warning('Admin login failed', ['ip' => $request->ip()]);

            throw new ApiException('InvalidPassword', 401, 'The password is incorrect.');
        }

        $token = bin2hex(random_bytes(32));
        User::admin()->tokens()->create([
            'name' => 'admin',
            'token' => hash('sha256', $token),
            'abilities' => ['*'],
        ]);
        Log::info('Admin login successful', ['ip' => $request->ip()]);

        return new JsonResponse(['token' => $token]);
    }
}
