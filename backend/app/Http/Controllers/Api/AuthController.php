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
use App\Support\ClientAddress;
use App\Support\Login;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use OpenApi\Spec as OAS;

/**
 * Accounts: sign-up, login and logout. A signed-in browser carries its session
 * in a cookie (App\Support\Login). Sign-up and login pass Cloudflare Turnstile
 * first (App\Services\Turnstile), when it is set up. Login is throttled to 10
 * attempts per 5 minutes per visitor, and sign-up to 5 accounts an hour, on top
 * of the API's limit; a visitor is an IPv4 address or an IPv6 /64
 * (App\Support\ClientAddress). That is checked here rather than by a second throttle
 * middleware, which would report its own, smaller X-RateLimit-Remaining: the
 * contract's header is the API limit's.
 */
final class AuthController extends Controller
{
    /** Login attempts allowed per visitor in LOGIN_WINDOW seconds, successful ones included. */
    public const int LOGIN_ATTEMPTS = 10;

    public const int LOGIN_WINDOW = 300;

    /** Accounts one visitor may create in SIGNUP_WINDOW seconds. */
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
     * GET /auth/session — The account this browser is signed in as, or null.
     * The page asks once as it starts, since it can't read the session cookie.
     */
    #[OAS\Operation\Get(
        path: '/auth/session',
        operationId: 'currentSession',
        summary: 'Who this browser is signed in as',
        tags: ['Auth'],
        responses: [
            new OAS\Response(response: 200, description: 'The account, or null when signed out', content: new OAS\MediaType\Json(ref: '#/components/schemas/SessionResponse')),
        ],
    )]
    public function session(Request $request): JsonResponse
    {
        $user = $request->user();

        return new JsonResponse(['user' => $user instanceof User ? new UserResource($user)->resolve($request) : null]);
    }

    /**
     * POST /auth/register — Create an account and sign this browser in as it.
     * Body: { username, password, remember?, turnstile_token? }.
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
                    new OAS\Property(property: 'remember', schema: new OAS\Schema(type: 'boolean', description: 'Keep this browser signed in for 30 days, not just until it closes.')),
                    new OAS\Property(property: 'turnstile_token', schema: new OAS\Schema(type: 'string', description: 'The Turnstile widget\'s token (action "register"), when GET /auth/config gives a site key.')),
                ],
            ),
        ),
        responses: [
            new OAS\Response(response: 201, description: 'The account, signed in', content: new OAS\MediaType\Json(ref: '#/components/schemas/SignInResponse')),
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

        $throttleKey = 'signup:' . ClientAddress::key($request);
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
        Login::start($user, $request->boolean('remember'));

        return new JsonResponse(['user' => new UserResource($user)->resolve($request)], 201);
    }

    /**
     * POST /auth/login — Sign this browser in with a username and password.
     * Body: { username, password, remember?, turnstile_token? }.
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
                    new OAS\Property(property: 'remember', schema: new OAS\Schema(type: 'boolean', description: 'Keep this browser signed in for 30 days, not just until it closes.')),
                    new OAS\Property(property: 'turnstile_token', schema: new OAS\Schema(type: 'string', description: 'The Turnstile widget\'s token (action "login"), when GET /auth/config gives a site key.')),
                ],
            ),
        ),
        responses: [
            new OAS\Response(response: 200, description: 'Signed in', content: new OAS\MediaType\Json(ref: '#/components/schemas/SignInResponse')),
            new OAS\Response(response: 400, description: 'MissingCredentials, CaptchaRequired', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 401, description: 'InvalidCredentials', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 403, description: 'CaptchaFailed', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 429, description: 'TooManyAttempts', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 503, description: 'CaptchaUnavailable', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function login(LoginRequest $request): JsonResponse
    {
        self::throttleLogin($request);
        $this->turnstile->verify(self::turnstileToken($request), 'login', $request->ip());

        // The column is COLLATE NOCASE, so the name matches in any case. An
        // unknown name still costs one hash check, against a hash made once, so
        // the answer's timing doesn't say which names exist.
        $password = $request->string('password')->toString();
        $user = User::query()->where('username', $request->string('username')->toString())->first();
        if (!Hash::check($password, $user->password ?? self::unknownAccountHash()) || $user === null) {
            Log::warning('Login failed', ['ip' => $request->ip()]);

            throw new ApiException('InvalidCredentials', 401, 'The username or password is incorrect.');
        }
        // A hash made with other settings (bcrypt, before 7.2.0, or weaker argon2id
        // ones) is replaced by one made with today's. This runs before the sign-in,
        // so the new session holds the new hash and stays signed in.
        if (Hash::needsRehash($user->password)) {
            $user->rehashPassword($password);
        }
        Log::info('Login', ['user_id' => $user->id, 'ip' => $request->ip()]);
        Login::start($user, $request->boolean('remember'));

        return new JsonResponse(['user' => new UserResource($user)->resolve($request)]);
    }

    /**
     * DELETE /auth/session — Log out this browser. The account's other
     * browsers stay signed in.
     */
    #[OAS\Operation\Delete(
        path: '/auth/session',
        operationId: 'logout',
        summary: 'Log out',
        tags: ['Auth'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        responses: [
            new OAS\Response(response: 204, description: 'Signed out'),
            new OAS\Response(response: 401, description: 'Unauthorized', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function logout(Request $request): Response
    {
        Login::end($request);

        return response()->noContent();
    }

    /**
     * Count a login attempt from this visitor, refusing it (429
     * TooManyAttempts) past the limit. A passkey login counts too
     * (PasskeyController).
     */
    public static function throttleLogin(Request $request): void
    {
        $throttleKey = 'login:' . ClientAddress::key($request);
        if (RateLimiter::tooManyAttempts($throttleKey, self::LOGIN_ATTEMPTS)) {
            Log::warning('Login rate limit exceeded', ['ip' => $request->ip()]);

            throw new ApiException('TooManyAttempts', 429, 'Too many login attempts. Please wait a few minutes and try again.', [
                'Retry-After' => (string) RateLimiter::availableIn($throttleKey),
            ]);
        }
        RateLimiter::hit($throttleKey, self::LOGIN_WINDOW);
    }

    /**
     * A hash for a login to check when no account has the name it gave, so
     * that costs one check as a known name does. It is made once with the
     * current settings (a different one when they change) and kept.
     */
    private static function unknownAccountHash(): string
    {
        return Cache::rememberForever(
            'login.unknown-account-hash.' . md5(json_encode(config('hashing.argon'), JSON_THROW_ON_ERROR)),
            static fn(): string => Hash::make(Str::random(40)),
        );
    }

    private static function turnstileToken(Request $request): ?string
    {
        $token = $request->input('turnstile_token');

        return is_string($token) ? $token : null;
    }
}
