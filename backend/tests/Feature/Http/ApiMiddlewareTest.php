<?php

declare(strict_types=1);

use App\Exceptions\ApiException;
use App\Http\Requests\ApiRequest;
use App\Models\User;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestBrowser;

use function Pest\Laravel\call;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeaders;

// The cross-cutting behavior of every endpoint, on test routes: problem
// details for every error, whatever the request accepts; the 401, 403 and 429
// the recorded contract shows for the real ones (the contract replay checks
// those too); malformed JSON; and a trailing slash.

const ALLOWED = 'https://gallery.test';

/** A request as the API's FormRequests fail it: the first broken rule's code. */
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
        return ['name.required' => ['InvalidName', 422], 'name.max' => ['NameTaken', 409]];
    }
}

/**
 * A problem details body.
 *
 * @return array<string, int|string>
 */
function problem(int $status, string $title, string $code, string $detail): array
{
    return ['type' => problemType($code), 'title' => $title, 'status' => $status, 'detail' => $detail];
}

beforeEach(function (): void {
    // As AppServiceProvider sets them at boot, from GALLERY_ALLOWED_ORIGINS.
    config(['gallery.allowed_origins' => [ALLOWED, 'http://localhost:5173'], 'cors.allowed_origins' => [ALLOWED, 'http://localhost:5173']]);

    Route::middleware('api')->prefix('api')->group(function (): void {
        Route::get('/test/public', fn() => new JsonResponse(['ok' => true]));
        Route::post('/test/protected', fn() => new JsonResponse(['ok' => true]))->middleware('auth');
        Route::post('/test/validated', fn(ExampleApiRequest $request) => new JsonResponse($request->validated()));
        Route::post('/test/echo', fn(Request $request) => new JsonResponse(['body' => $request->all()]));
        Route::post('/test/framework-validation', function (Request $request): JsonResponse {
            Validator::make($request->all(), ['size' => ['required', 'integer']])->validate();

            return new JsonResponse(['ok' => true]);
        });
        Route::get('/test/broken', fn() => throw new RuntimeException('Something broke'));
    });
});

test('a write not signed in is 401 Unauthorized, and still counted by the rate limiter', function (): void {
    withHeaders(['Origin' => ALLOWED])->postJson('/api/test/protected')
        ->assertStatus(401)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertExactJson(problem(401, 'Unauthorized', 'Unauthorized', 'Authentication is required. Please log in and try again.'))
        ->assertHeader('X-RateLimit-Remaining', '119');
    // There are no bearer tokens.
    withHeaders(['Origin' => ALLOWED, 'Authorization' => 'Bearer ' . str_repeat('a', 64)])->postJson('/api/test/protected')->assertStatus(401);
});

test('a browser that logged in passes, by its session cookie alone', function (): void {
    User::factory()->create(['username' => 'someone', 'password' => 'secret-password']);
    $browser = new TestBrowser();

    $browser->send('POST', '/api/test/protected')->assertStatus(401);
    $browser->send('POST', '/api/auth/login', ['username' => 'someone', 'password' => 'secret-password'])->assertOk();
    $browser->send('POST', '/api/test/protected')->assertOk();
    new TestBrowser()->send('POST', '/api/test/protected')->assertStatus(401);
});

test('a write the browser doesn\'t mark same-origin is refused, even from an allowed origin', function (): void {
    // PreventRequestForgery lets every request through in tests; here it
    // checks them as it does on the server.
    app()->bind(PreventRequestForgery::class, static fn(Application $app): PreventRequestForgery => new class ($app, $app->make('encrypter')) extends PreventRequestForgery {
        protected function runningUnitTests(): bool
        {
            return false;
        }
    });
    $from = static fn(?string $site) => withHeaders(['Origin' => ALLOWED, ...($site === null ? [] : ['Sec-Fetch-Site' => $site])])->postJson('/api/test/protected');

    $from('cross-site')
        ->assertStatus(403)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertExactJson(problem(403, 'Forbidden', 'ForbiddenOrigin', 'The request origin is not allowed.'))
        ->assertHeaderMissing('X-RateLimit-Remaining');
    $from('same-site')->assertStatus(403);
    // A client that doesn't say, as no current browser is.
    $from(null)->assertStatus(403);
    // Past the check: not signed in.
    $from('same-origin')->assertStatus(401);
    getJson('/api/test/public')->assertOk();
});

test('the origin check refuses a write before authentication and the rate limiter see it', function (): void {
    withHeaders(['Origin' => 'https://evil.example'])->postJson('/api/test/protected')
        ->assertStatus(403)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertExactJson(problem(403, 'Forbidden', 'ForbiddenOrigin', 'The request origin is not allowed.'))
        ->assertHeaderMissing('X-RateLimit-Remaining')
        ->assertHeaderMissing('Access-Control-Allow-Origin');

    postJson('/api/test/protected')->assertStatus(403);
    withHeaders(['Referer' => 'https://evil.example/tags'])->postJson('/api/test/protected')->assertStatus(403);
});

test('without an Origin, an allowed Referer passes the origin check, port included', function (): void {
    withHeaders(['Referer' => 'http://localhost:5173/tags?page=2'])->actingAs(adminUser())->postJson('/api/test/protected')->assertOk();
    withHeaders(['Referer' => ALLOWED . '/tags'])->actingAs(adminUser())->postJson('/api/test/protected')->assertOk();
});

test('a read needs neither an Origin nor a sign-in', function (): void {
    getJson('/api/test/public')->assertOk()->assertHeader('X-RateLimit-Remaining', '119');
});

test('the 121st request in a minute is 429 RateLimitExceeded, saying when to retry', function (): void {
    for ($i = 0; $i < 120; $i++) {
        getJson('/api/test/public')->assertOk();
    }

    $response = getJson('/api/test/public')
        ->assertStatus(429)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertExactJson(problem(429, 'Too Many Requests', 'RateLimitExceeded', 'Too many requests. Please wait a moment and try again.'))
        ->assertHeader('X-RateLimit-Remaining', '0');

    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);

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
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertExactJson(problem(500, 'Internal Server Error', 'ServerError', 'Something went wrong on the server. Please try again.'));
});

test('every error is problem details, whatever the request accepts', function (string $accept): void {
    $send = static fn(string $method, string $path) => call($method, $path, [], [], [], ['HTTP_ACCEPT' => $accept, 'HTTP_ORIGIN' => ALLOWED]);

    $send('GET', '/api/no-such-route')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertExactJson(problem(404, 'Not Found', 'NotFound', 'The requested endpoint does not exist.'));
    $send('DELETE', '/api/test/public')
        ->assertStatus(405)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertHeader('Allow', 'GET, HEAD')
        ->assertExactJson(problem(405, 'Method Not Allowed', 'MethodNotAllowed', 'This endpoint does not support DELETE requests.'));
    $send('GET', '/api/test/broken')->assertStatus(500)->assertHeader('Content-Type', 'application/problem+json');
})->with(['*/*', 'text/html', 'application/json']);

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

test('an ApiRequest fails with its first broken rule\'s code, status and message, and every broken field\'s location', function (): void {
    $post = static fn(array $body) => withHeaders(['Origin' => ALLOWED])->postJson('/api/test/validated', $body);

    $post([])->assertUnprocessable()->assertExactJson([
        ...problem(422, 'Unprocessable Content', 'InvalidName', 'Name is required.'),
        'errors' => [['message' => 'Name is required.', 'location' => 'body.name']],
    ]);
    $post(['name' => 'too long'])->assertConflict()->assertJsonPath('type', problemType('NameTaken'))->assertJsonPath('errors.0.location', 'body.name');
    // Every broken field is listed; the code and message are the first one's.
    $post(['name' => 'too long', 'count' => 'x'])->assertConflict()->assertJsonPath('type', problemType('NameTaken'))->assertJsonCount(2, 'errors')->assertJsonPath('errors.1.location', 'body.count');
    $post(['name' => 'ok', 'count' => 'x'])->assertUnprocessable()->assertJsonPath('type', problemType('InvalidInput'));
    $post(['name' => 'ok', 'count' => 3])->assertOk()->assertExactJson(['name' => 'ok', 'count' => 3]);
});

test('a validation the framework fails is 422 InvalidInput, with every broken field', function (): void {
    Exceptions::fake();

    withHeaders(['Origin' => ALLOWED])->postJson('/api/test/framework-validation', ['size' => 'big'])
        ->assertUnprocessable()
        ->assertJsonPath('type', problemType('InvalidInput'))
        ->assertJsonPath('errors.0.location', 'body.size');
    Exceptions::assertNotReported(ValidationException::class);
});

test('a body that says it is JSON and isn\'t is 400 MalformedJson', function (): void {
    $send = static fn(string $body, string $type) => call('POST', '/api/test/echo', [], [], [], ['HTTP_ORIGIN' => ALLOWED, 'CONTENT_TYPE' => $type, 'HTTP_ACCEPT' => 'application/json'], $body);

    $send('{"name": ', 'application/json')
        ->assertStatus(400)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertExactJson(problem(400, 'Bad Request', 'MalformedJson', 'The request body is not valid JSON.'));
    $send('{"name": "ok"}', 'application/json')->assertOk()->assertExactJson(['body' => ['name' => 'ok']]);
    // No body at all is no body, not a malformed one.
    $send('', 'application/json')->assertOk();
    $send('name=ok', 'application/x-www-form-urlencoded')->assertOk();
});

test('a path with a trailing slash answers as the path without it', function (): void {
    getJson('/api/test/public/')->assertOk()->assertExactJson(['ok' => true]);
    getJson('/api/version/')->assertOk()->assertJsonStructure(['version', 'apiVersion']);
    getJson('/api/media/count/')->assertOk()->assertExactJson(getJson('/api/media/count')->json());
    getJson('/api/tags/?limit=5')->assertOk()->assertHeader('X-Total-Count', '0');
    withHeaders(['Origin' => ALLOWED])->postJson('/api/test/echo/', ['name' => 'ok'])->assertOk()->assertExactJson(['body' => ['name' => 'ok']]);
    getJson('/api/no-such-route/')->assertNotFound()->assertJsonPath('type', problemType('NotFound'));
});
