<?php

declare(strict_types=1);

use App\Exceptions\ApiException;
use App\Http\Requests\ApiRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeaders;

// The cross-cutting behavior the recorded contract fixes for every endpoint,
// on test routes: what the 401, 403 and 429 recordings show for the real ones,
// which arrive in Phase 4 (the contract replay then checks them too).

const ALLOWED = 'https://gallery.test';

/** A request as the contract's FormRequests fail it: the first broken rule's code. */
final class ExampleApiRequest extends ApiRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:5'],
            'count' => ['integer'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['name.required' => 'Name is required.', 'name.max' => 'Name must be 5 characters or fewer.'];
    }

    protected function errorCodes(): array
    {
        return ['name.required' => ['InvalidName', 400], 'name.max' => ['NameTooLong', 422]];
    }
}

beforeEach(function (): void {
    // As AppServiceProvider sets them at boot, from GALLERY_ALLOWED_ORIGINS.
    config(['gallery.allowed_origins' => [ALLOWED, 'http://localhost:5173'], 'cors.allowed_origins' => [ALLOWED, 'http://localhost:5173']]);

    Route::middleware('api')->prefix('api')->group(function (): void {
        Route::get('/test/public', fn() => new JsonResponse(['ok' => true]));
        Route::post('/test/protected', fn() => new JsonResponse(['ok' => true]))->middleware('auth:sanctum');
        Route::post('/test/validated', fn(ExampleApiRequest $request) => new JsonResponse($request->validated()));
        Route::get('/test/broken', fn() => throw new RuntimeException('Something broke'));
    });
});

function adminToken(?Carbon $issued = null): string
{
    $token = bin2hex(random_bytes(32));
    $record = User::admin()->tokens()->create(['name' => 'admin', 'token' => hash('sha256', $token), 'abilities' => ['*']]);
    if ($issued !== null) {
        $record->forceFill(['created_at' => $issued])->save();
    }

    return $token;
}

test('a write without a token is 401 Unauthorized, and still counted by the rate limiter', function (): void {
    withHeaders(['Origin' => ALLOWED])->postJson('/api/test/protected')
        ->assertStatus(401)
        ->assertExactJson(['error' => 'Unauthorized', 'message' => 'Authentication is required. Please log in and try again.'])
        ->assertHeader('X-RateLimit-Remaining', '119');
});

test('an expired token, or one without the Bearer scheme, is 401', function (): void {
    $expired = adminToken(now()->subMinutes(1441));
    $current = adminToken();

    withHeaders(['Origin' => ALLOWED, 'Authorization' => "Bearer {$expired}"])->postJson('/api/test/protected')->assertStatus(401);
    withHeaders(['Origin' => ALLOWED, 'Authorization' => "Token {$current}"])->postJson('/api/test/protected')->assertStatus(401);
});

test('a token from login is accepted', function (): void {
    config(['gallery.admin_password' => 'secret']);
    $token = withHeaders(['Origin' => ALLOWED])->postJson('/api/auth/login', ['password' => 'secret'])->assertOk()->json('token');

    expect($token)->toBeString()->toMatch('/^[0-9a-f]{64}$/');
    withHeaders(['Origin' => ALLOWED, 'Authorization' => "Bearer {$token}"])->postJson('/api/test/protected')->assertOk();
});

test('the origin check refuses a write before authentication and the rate limiter see it', function (): void {
    withHeaders(['Origin' => 'https://evil.example'])->postJson('/api/test/protected')
        ->assertStatus(403)
        ->assertExactJson(['error' => 'ForbiddenOrigin', 'message' => 'The request origin is not allowed.'])
        ->assertHeaderMissing('X-RateLimit-Remaining')
        ->assertHeaderMissing('Access-Control-Allow-Origin');

    postJson('/api/test/protected')->assertStatus(403);
    withHeaders(['Referer' => 'https://evil.example/tags'])->postJson('/api/test/protected')->assertStatus(403);
});

test('without an Origin, an allowed Referer passes the origin check, port included', function (): void {
    $token = adminToken();

    withHeaders(['Referer' => 'http://localhost:5173/tags?page=2', 'Authorization' => "Bearer {$token}"])->postJson('/api/test/protected')->assertOk();
    withHeaders(['Referer' => ALLOWED . '/tags', 'Authorization' => "Bearer {$token}"])->postJson('/api/test/protected')->assertOk();
});

test('a read needs neither an Origin nor a token', function (): void {
    getJson('/api/test/public')->assertOk()->assertHeader('X-RateLimit-Remaining', '119');
});

test('the 121st request in a minute is 429 RateLimitExceeded, saying when to retry', function (): void {
    for ($i = 0; $i < 120; $i++) {
        getJson('/api/test/public')->assertOk();
    }

    $response = getJson('/api/test/public')
        ->assertStatus(429)
        ->assertJsonPath('error', 'RateLimitExceeded')
        ->assertJsonPath('message', 'Too many requests. Please wait a moment and try again.')
        ->assertHeader('X-RateLimit-Remaining', '0');

    expect($response->json('retry_after'))->toBeInt()->toBeGreaterThan(0)
        ->and($response->headers->get('Retry-After'))->toBe((string) $response->json('retry_after'));

    // The origin check still runs first.
    withHeaders(['Origin' => 'https://evil.example'])->postJson('/api/test/protected')->assertStatus(403);
});

test('every response carries the security headers', function (): void {
    foreach ([getJson('/api/test/public'), getJson('/api/no-such-route'), postJson('/api/test/protected')] as $response) {
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeader('X-Content-Type-Options', 'nosniff');
    }
});

test('an uncaught error is 500 ServerError, without the exception\'s details', function (): void {
    getJson('/api/test/broken')
        ->assertStatus(500)
        ->assertExactJson(['error' => 'ServerError', 'message' => 'Something went wrong on the server. Please try again.']);
});

test('an API error is logged only when it is the server\'s (5xx), not the client\'s (4xx)', function (): void {
    Exceptions::fake();
    Route::middleware('api')->prefix('api')->group(function (): void {
        Route::get('/test/missing', fn() => throw new ApiException('NotFound', 404, 'Not here.'));
        Route::get('/test/unavailable', fn() => throw new ApiException('DanbooruUnavailable', 503, 'Try again shortly.'));
    });

    getJson('/api/test/missing')->assertStatus(404);
    Exceptions::assertNothingReported();

    getJson('/api/test/unavailable')->assertStatus(503);
    getJson('/api/test/broken')->assertStatus(500);
    Exceptions::assertReported(static fn(ApiException $e): bool => $e->status === 503);
    Exceptions::assertReported(RuntimeException::class);
});

test('an ApiRequest fails with its first broken rule\'s code, status and message', function (): void {
    $token = adminToken();
    $post = static fn(array $body) => withHeaders(['Origin' => ALLOWED, 'Authorization' => "Bearer {$token}"])->postJson('/api/test/validated', $body);

    $post([])->assertStatus(400)->assertExactJson(['error' => 'InvalidName', 'message' => 'Name is required.', 'errors' => ['name' => ['Name is required.']]]);
    $post(['name' => 'too long'])->assertStatus(422)->assertExactJson(['error' => 'NameTooLong', 'message' => 'Name must be 5 characters or fewer.', 'errors' => ['name' => ['Name must be 5 characters or fewer.']]]);
    // Every broken field is listed; the code and message are the first one's.
    $post(['name' => 'too long', 'count' => 'x'])->assertStatus(422)->assertJsonPath('error', 'NameTooLong')->assertJsonCount(2, 'errors');
    $post(['name' => 'ok', 'count' => 'x'])->assertStatus(400)->assertJsonPath('error', 'InvalidInput');
    $post(['name' => 'ok', 'count' => 3])->assertOk()->assertExactJson(['name' => 'ok', 'count' => 3]);
});
