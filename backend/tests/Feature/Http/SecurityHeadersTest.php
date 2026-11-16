<?php

declare(strict_types=1);

use App\Http\Controllers\SpaController;
use App\Support\ContentSecurityPolicy;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\withHeaders;

// What every response says about itself (nosniff, Referrer-Policy, no framing),
// and the Content-Security-Policy: a page's admits the gallery's own files, what
// carries the answer's nonce and Turnstile, and nothing inline; anything else
// may load nothing.

/** An index.html as the build writes it, nonce placeholders and all, for the shell to serve. */
function builtShell(): string
{
    $placeholder = SpaController::NONCE_PLACEHOLDER;
    $folder = sys_get_temp_dir() . '/gallery-shell-' . bin2hex(random_bytes(4));
    File::ensureDirectoryExists($folder);
    File::put("{$folder}/index.html", <<<HTML
        <!doctype html>
        <html lang="en">
          <head>
            <meta charset="UTF-8" />
            <title>Gallery</title>
            <script type="module" crossorigin src="/assets/index-abc.js" nonce="{$placeholder}"></script>
            <link rel="stylesheet" crossorigin href="/assets/index-abc.css" nonce="{$placeholder}">
            <meta property="csp-nonce" nonce="{$placeholder}">
          </head>
          <body>
            <div id="app"></div>
          </body>
        </html>
        HTML);

    return "{$folder}/index.html";
}

/**
 * The policy's directives, by name.
 *
 * @return array<string, list<string>>
 */
function directives(string $policy): array
{
    $directives = [];
    foreach (array_filter(array_map(trim(...), explode(';', $policy)), static fn(string $directive): bool => $directive !== '') as $directive) {
        $parts = explode(' ', $directive);
        $directives[array_shift($parts)] = $parts;
    }

    return $directives;
}

/** @param TestResponse<Response> $response */
function enforcedPolicy(TestResponse $response): string
{
    return (string) $response->headers->get('Content-Security-Policy');
}

beforeEach(function (): void {
    config(['gallery.spa_index' => builtShell()]);
});

afterEach(function (): void {
    $folders = glob(sys_get_temp_dir() . '/gallery-shell-*');
    foreach ($folders === false ? [] : $folders as $folder) {
        File::deleteDirectory($folder);
    }
});

test('every response is nosniff, same-origin for referrers, and not to be framed', function (): void {
    config(['gallery.allowed_origins' => ['https://gallery.test']]);
    $responses = [
        'a page' => get('/favorites'),
        'a missing file' => get('/assets/missing.js'),
        'an API answer' => getJson('/api/version'),
        'an API 404' => getJson('/api/no-such-route'),
        'an API 405' => withHeaders(['Origin' => 'https://gallery.test'])->postJson('/api/version'),
        'a refused write' => withHeaders(['Origin' => 'https://evil.example'])->postJson('/api/tags', ['tag_name' => 'x']),
        'the API reference' => get('/api/docs'),
    ];
    config(['gallery.spa_index' => '/no/such/index.html']);
    $responses['the shell without its build'] = get('/');

    foreach ($responses as $what => $response) {
        expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff', $what)
            ->and($response->headers->get('Referrer-Policy'))->toBe('same-origin', $what)
            ->and(directives(enforcedPolicy($response))['frame-ancestors'] ?? null)->toBe(["'none'"], $what);
    }
});

test('a page is sent with its policy, naming a fresh 128-bit nonce in the shell and the header alike', function (): void {
    $first = get('/tags')->assertOk();
    $second = get('/tags')->assertOk();

    $nonces = [];
    foreach ([$first, $second] as $response) {
        $directives = directives(enforcedPolicy($response));
        preg_match_all('/nonce="([^"]*)"/', (string) $response->baseResponse->getContent(), $inShell);
        $nonce = $inShell[1][0] ?? '';

        expect($inShell[1])->toBe([$nonce, $nonce, $nonce])
            ->and(strlen((string) base64_decode($nonce, true)))->toBe(16)
            ->and((string) $response->baseResponse->getContent())->not->toContain(SpaController::NONCE_PLACEHOLDER)
            ->and($directives)->toBe([
                'default-src' => ["'self'"],
                'script-src' => ["'self'", "'nonce-{$nonce}'", 'https://challenges.cloudflare.com'],
                'style-src' => ["'self'", "'nonce-{$nonce}'"],
                'object-src' => ["'none'"],
                'base-uri' => ["'none'"],
                'form-action' => ["'self'"],
                'frame-ancestors' => ["'none'"],
                'frame-src' => ['https://challenges.cloudflare.com'],
            ])
            ->and($response->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse();
        $nonces[] = $nonce;
    }

    expect($nonces[0])->not->toBe($nonces[1]);
});

test('no page allows inline script or style, or eval', function (string $path): void {
    $policy = enforcedPolicy(get($path)->assertOk());

    expect(directives($policy)['default-src'] ?? null)->toBe(["'self'"]);
    foreach (['unsafe-inline', 'unsafe-eval', 'unsafe-hashes'] as $loosening) {
        expect($policy)->not->toContain($loosening);
    }
})->with(['the shell' => '/', 'the shell at /index.html' => '/index.html', 'the API reference' => '/api/docs']);

test('anything that is not a page may load nothing', function (): void {
    expect(enforcedPolicy(getJson('/api/version')->assertOk()))->toBe(ContentSecurityPolicy::NONE)
        ->and(enforcedPolicy(get('/assets/missing.js')->assertNotFound()))->toBe(ContentSecurityPolicy::NONE)
        ->and(ContentSecurityPolicy::NONE)->toBe("default-src 'none'; frame-ancestors 'none'");
});

test('the API reference is Swagger UI, from the gallery\'s own files alone', function (): void {
    $response = get('/api/docs')->assertOk()->assertHeader('Content-Type', 'text/html; charset=utf-8');
    $html = (string) $response->baseResponse->getContent();

    expect(directives(enforcedPolicy($response)))->toBe([
        'default-src' => ["'self'"],
        'script-src' => ["'self'"],
        'style-src' => ["'self'"],
        'object-src' => ["'none'"],
        'base-uri' => ["'none'"],
        'form-action' => ["'self'"],
        'frame-ancestors' => ["'none'"],
        'img-src' => ["'self'", 'data:'],
    ])
        ->and($html)->toContain('<link rel="stylesheet" href="/api-docs/swagger-ui.css" />')
        ->toContain('<script src="/api-docs/swagger-ui-bundle.js"></script>')
        ->toContain('<script src="/api-docs/reference.js"></script>')
        ->and(preg_match_all('#<script(?![^>]* src=)#', $html))->toBe(0);
    expect($html)->not->toContain('http');
    expect($html)->not->toContain('style=');
});

test('with the report-only switch on, every policy only reports', function (): void {
    config(['gallery.csp_report_only' => true]);

    foreach ([get('/favorites'), getJson('/api/version'), get('/api/docs'), get('/assets/missing.js')] as $response) {
        expect($response->headers->has('Content-Security-Policy'))->toBeFalse()
            ->and(directives((string) $response->headers->get('Content-Security-Policy-Report-Only')))->toHaveKey('frame-ancestors');
    }
});
