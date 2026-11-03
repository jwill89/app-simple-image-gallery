<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Turnstile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use OpenApi\Spec as OAS;

/**
 * Accounts: sign-up, login and logout. Sign-up and login pass Cloudflare
 * Turnstile first (App\Services\Turnstile), when it is set up. Login is
 * throttled to 10 attempts per 5 minutes per IP, and sign-up to 5 accounts an
 * hour, on top of the API's limit. That is checked here rather than by a second
 * throttle middleware, which would report its own, smaller
 * X-RateLimit-Remaining: the contract's header is the API limit's.
 */
final class AuthController extends Controller
{
    /** Login attempts allowed per IP in LOGIN_WINDOW seconds, successful ones included. */
    private const int LOGIN_ATTEMPTS = 10;

    private const int LOGIN_WINDOW = 300;

    /** Accounts one IP may create in SIGNUP_WINDOW seconds. */
    private const int SIGNUPS = 5;

    private const int SIGNUP_WINDOW = 3600;

    public function __construct(private readonly Turnstile $turnstile) {}

    /**
     * GET /auth/config — What the sign-up and login pages need to know:
     * whether sign-up is open, and the Turnstile site key (null while the bot
     * check is off).
     */
    #[OAS\Operation\Get(
        path: '/auth/config',
        operationId: 'authConfig',
        summary: 'Sign-up and login settings',
        tags: ['Auth'],
        responses: [
            new OAS\Response(response: 200, description: 'The settings', content: new OAS\MediaType\Json(properties: [
                new OAS\Property(property: 'signup_open', schema: new OAS\Schema(type: 'boolean')),
                new OAS\Property(property: 'turnstile_site_key', schema: new OAS\Schema(type: 'string', nullable: true)),
            ])),
        ],
    )]
    public function config(): JsonResponse
    {
        return new JsonResponse([
            'signup_open' => config()->boolean('gallery.allow_signup'),
            'turnstile_site_key' => Turnstile::siteKey(),
        ]);
    }

    /**
     * POST /auth/register — Create an account and sign it in. Body:
     * { username, password, remember?, turnstile_token? }.
     */
    #[OAS\Operation\Post(
        path: '/auth/register',
        operationId: 'register',
        summary: 'Create an account',
        tags: ['Auth'],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['username', 'password'],
                properties: [
                    new OAS\Property(property: 'username', schema: new OAS\Schema(type: 'string', maxLength: 32, minLength: 3, pattern: '^[A-Za-z0-9._-]{3,32}$')),
                    new OAS\Property(property: 'password', schema: new OAS\Schema(type: 'string', maxLength: 200, minLength: 8)),
                    new OAS\Property(property: 'remember', schema: new OAS\Schema(type: 'boolean', description: 'Keep the login for 30 days instead of one.')),
                    new OAS\Property(property: 'turnstile_token', schema: new OAS\Schema(type: 'string', description: 'The Turnstile widget\'s token (action "register"), when GET /auth/config gives a site key.')),
                ],
            ),
        ),
        responses: [
            new OAS\Response(response: 201, description: 'The account, signed in', content: new OAS\MediaType\Json(ref: '#/components/schemas/LoginResponse')),
            new OAS\Response(response: 400, description: 'InvalidUsername, WeakPassword, CaptchaRequired', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 403, description: 'SignupClosed, CaptchaFailed', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 409, description: 'UsernameTaken', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 429, description: 'TooManySignups', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 503, description: 'CaptchaUnavailable', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function register(RegisterRequest $request): JsonResponse
    {
        if (!config()->boolean('gallery.allow_signup')) {
            throw new ApiException('SignupClosed', 403, 'New accounts are not being accepted.');
        }

        $throttleKey = 'signup:' . $request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, self::SIGNUPS)) {
            throw new ApiException('TooManySignups', 429, 'Too many accounts from this address. Please try again later.', [
                'Retry-After' => (string) RateLimiter::availableIn($throttleKey),
            ]);
        }
        RateLimiter::hit($throttleKey, self::SIGNUP_WINDOW);
        $this->turnstile->verify(self::turnstileToken($request), 'register', $request->ip());

        $user = User::query()->create([
            'username' => $request->string('username')->toString(),
            'password' => $request->string('password')->toString(),
            // Never from the request: an admin is made by another admin, or on the server.
            'is_admin' => false,
        ]);
        $user->defaultList();
        Log::info('Account created', ['user_id' => $user->id, 'ip' => $request->ip()]);

        return new JsonResponse([
            'token' => $user->issueToken($request->boolean('remember')),
            'user' => new UserResource($user)->resolve($request),
        ], 201);
    }

    /**
     * POST /auth/login — Exchange a username and password for a bearer token.
     * Body: { username, password, remember?, turnstile_token? }. The token
     * lasts a day, or 30 with remember.
     */
    #[OAS\Operation\Post(
        path: '/auth/login',
        operationId: 'login',
        summary: 'Log in',
        tags: ['Auth'],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['username', 'password'],
                properties: [
                    new OAS\Property(property: 'username', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'password', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'remember', schema: new OAS\Schema(type: 'boolean', description: 'Keep the login for 30 days instead of one.')),
                    new OAS\Property(property: 'turnstile_token', schema: new OAS\Schema(type: 'string', description: 'The Turnstile widget\'s token (action "login"), when GET /auth/config gives a site key.')),
                ],
            ),
        ),
        responses: [
            new OAS\Response(response: 200, description: 'Issued token', content: new OAS\MediaType\Json(ref: '#/components/schemas/LoginResponse')),
            new OAS\Response(response: 400, description: 'MissingCredentials, CaptchaRequired', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 401, description: 'InvalidCredentials', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 403, description: 'CaptchaFailed', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 429, description: 'TooManyAttempts', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 503, description: 'CaptchaUnavailable', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function login(LoginRequest $request): JsonResponse
    {
        $throttleKey = 'login:' . $request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, self::LOGIN_ATTEMPTS)) {
            Log::warning('Login rate limit exceeded', ['ip' => $request->ip()]);

            throw new ApiException('TooManyAttempts', 429, 'Too many login attempts. Please wait a few minutes and try again.', [
                'Retry-After' => (string) RateLimiter::availableIn($throttleKey),
            ]);
        }
        RateLimiter::hit($throttleKey, self::LOGIN_WINDOW);
        $this->turnstile->verify(self::turnstileToken($request), 'login', $request->ip());

        // The column is COLLATE NOCASE, so the name matches in any case. An
        // unknown name still costs a hash check, so the answer's timing doesn't
        // say which names exist.
        $user = User::query()->where('username', $request->string('username')->toString())->first();
        $hash = $user->password ?? Hash::make(Str::random(40));
        if (!Hash::check($request->string('password')->toString(), $hash) || $user === null) {
            Log::warning('Login failed', ['ip' => $request->ip()]);

            throw new ApiException('InvalidCredentials', 401, 'The username or password is incorrect.');
        }
        Log::info('Login', ['user_id' => $user->id, 'ip' => $request->ip()]);

        return new JsonResponse([
            'token' => $user->issueToken($request->boolean('remember')),
            'user' => new UserResource($user)->resolve($request),
        ]);
    }

    /**
     * GET /auth/me — The account the token signs in.
     */
    #[OAS\Operation\Get(
        path: '/auth/me',
        operationId: 'currentUser',
        summary: 'The signed-in account',
        tags: ['Auth'],
        security: [new OAS\Security\Requirement(scheme: 'bearerAuth')],
        responses: [
            new OAS\Response(response: 200, description: 'The account', content: new OAS\MediaType\Json(ref: '#/components/schemas/User')),
            new OAS\Response(response: 401, description: 'Unauthorized', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /**
     * DELETE /auth/token — Log out: the token this request carries stops
     * working at once, rather than when it expires.
     */
    #[OAS\Operation\Delete(
        path: '/auth/token',
        operationId: 'logout',
        summary: 'Log out (revoke this token)',
        tags: ['Auth'],
        security: [new OAS\Security\Requirement(scheme: 'bearerAuth')],
        responses: [
            new OAS\Response(response: 204, description: 'Revoked'),
            new OAS\Response(response: 401, description: 'Unauthorized', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function logout(Request $request): Response
    {
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->noContent();
    }

    private static function turnstileToken(Request $request): ?string
    {
        $token = $request->input('turnstile_token');

        return is_string($token) ? $token : null;
    }
}
