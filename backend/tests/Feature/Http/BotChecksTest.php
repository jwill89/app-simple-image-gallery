<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// What keeps bots off sign-up and login: Cloudflare Turnstile, and limits
// counted per visitor, which behind Cloudflare means trusting its addresses
// (App\Support\Cloudflare) for the visitor's.

const SITEVERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

/**
 * Turnstile on, with Cloudflare answering $result to every check.
 *
 * @param array<string, mixed> $result
 */
function turnstileAnswers(array $result): void
{
    config(['gallery.turnstile.site_key' => 'site-key', 'gallery.turnstile.secret' => 'secret-key']);
    Http::fake([SITEVERIFY => Http::response($result)]);
}

beforeEach(function (): void {
    config(['gallery.allowed_origins' => ['https://gallery.test'], 'cors.allowed_origins' => ['https://gallery.test'], 'gallery.allow_signup' => true]);
    User::factory()->create(['username' => 'reader', 'password' => 'long-enough']);
});

test('the pages learn whether sign-up is open, and the site key only while the check is on', function (): void {
    signedOut()->getJson('/api/auth/config')->assertOk()->assertExactJson(['signup_open' => true, 'turnstile_site_key' => null]);

    config(['gallery.allow_signup' => false, 'gallery.turnstile.site_key' => 'site-key', 'gallery.turnstile.secret' => 'secret-key']);
    signedOut()->getJson('/api/auth/config')->assertOk()->assertExactJson(['signup_open' => false, 'turnstile_site_key' => 'site-key']);
});

test('with the check on, login and sign-up need a token Cloudflare passes, for their own form', function (): void {
    turnstileAnswers(['success' => true, 'action' => 'login']);
    $login = ['username' => 'reader', 'password' => 'long-enough'];

    fromGallery()->postJson('/api/auth/login', $login)
        ->assertStatus(400)->assertJson(['error' => 'CaptchaRequired']);
    fromGallery()->postJson('/api/auth/login', [...$login, 'turnstile_token' => 'token'])->assertOk();
    Http::assertSent(static fn(Request $request): bool => $request->url() === SITEVERIFY
        && $request['secret'] === 'secret-key' && $request['response'] === 'token' && $request['remoteip'] === '127.0.0.1');

    // A login token, replayed at sign-up.
    fromGallery()->postJson('/api/auth/register', ['username' => 'newcomer', 'password' => 'long-enough', 'turnstile_token' => 'token'])
        ->assertStatus(403)->assertJson(['error' => 'CaptchaFailed']);
    expect(User::query()->where('username', 'newcomer')->exists())->toBeFalse();
});

test('a token Cloudflare turns down is refused before the password is even checked', function (): void {
    turnstileAnswers(['success' => false, 'error-codes' => ['invalid-input-response']]);

    fromGallery()->postJson('/api/auth/login', ['username' => 'reader', 'password' => 'wrong-password', 'turnstile_token' => 'used'])
        ->assertStatus(403)->assertExactJson(['error' => 'CaptchaFailed', 'message' => 'The check that you\'re not a bot didn\'t pass. Please try it again.']);
});

test('with Cloudflare out of reach, nobody signs in: 503, never a 502 Cloudflare would swallow', function (): void {
    config(['gallery.turnstile.site_key' => 'site-key', 'gallery.turnstile.secret' => 'secret-key']);
    Http::fake([SITEVERIFY => static fn() => throw new ConnectionException('timed out')]);

    fromGallery()->postJson('/api/auth/login', ['username' => 'reader', 'password' => 'long-enough', 'turnstile_token' => 'token'])
        ->assertStatus(503)->assertJson(['error' => 'CaptchaUnavailable']);
});

test('behind Cloudflare, each visitor has their own login limit', function (): void {
    $fromCloudflare = static fn(string $visitor) => fromGallery(['X-Forwarded-For' => $visitor])
        ->withServerVariables(['REMOTE_ADDR' => '162.158.10.20']);

    foreach (range(1, 10) as $ignored) {
        $fromCloudflare('203.0.113.7')->postJson('/api/auth/login', ['username' => 'reader', 'password' => 'wrong-password'])->assertStatus(401);
    }
    $fromCloudflare('203.0.113.7')->postJson('/api/auth/login', ['username' => 'reader', 'password' => 'long-enough'])->assertStatus(429);

    // Another visitor, reaching the gallery through the same Cloudflare server.
    $fromCloudflare('198.51.100.9')->postJson('/api/auth/login', ['username' => 'reader', 'password' => 'long-enough'])->assertOk();
});

test('an IPv6 visitor is counted by its /64, not by the address it happens to use', function (): void {
    $fromCloudflare = static fn(string $visitor) => fromGallery(['X-Forwarded-For' => $visitor])
        ->withServerVariables(['REMOTE_ADDR' => '162.158.10.20']);

    // Ten attempts, each from another address in 2001:db8:1:2::/64.
    foreach (range(1, 10) as $n) {
        $fromCloudflare("2001:db8:1:2::{$n}")->postJson('/api/auth/login', ['username' => 'reader', 'password' => 'wrong-password'])->assertStatus(401);
    }
    $fromCloudflare('2001:db8:1:2:ffff:ffff:ffff:ffff')->postJson('/api/auth/login', ['username' => 'reader', 'password' => 'long-enough'])->assertStatus(429);
    $fromCloudflare('2001:0db8:0001:0002:0000:0000:0000:0001')->postJson('/api/auth/login', ['username' => 'reader', 'password' => 'long-enough'])->assertStatus(429);

    // The next /64 is another subscriber.
    $fromCloudflare('2001:db8:1:3::1')->postJson('/api/auth/login', ['username' => 'reader', 'password' => 'long-enough'])->assertOk();
});

test('the API\'s own limit counts an IPv6 visitor by its /64 too', function (): void {
    $fromCloudflare = static fn(string $visitor) => fromGallery(['X-Forwarded-For' => $visitor])
        ->withServerVariables(['REMOTE_ADDR' => '162.158.10.20']);

    $fromCloudflare('2001:db8:1:2::1')->getJson('/api/auth/config')->assertOk()->assertHeader('X-RateLimit-Remaining', '119');
    $fromCloudflare('2001:db8:1:2::2')->getJson('/api/auth/config')->assertOk()->assertHeader('X-RateLimit-Remaining', '118');
    $fromCloudflare('2001:db8:1:3::1')->getJson('/api/auth/config')->assertOk()->assertHeader('X-RateLimit-Remaining', '119');
});

test('from anywhere else, a forged X-Forwarded-For doesn\'t escape the limit', function (): void {
    foreach (range(1, 10) as $n) {
        fromGallery(['X-Forwarded-For' => "203.0.113.{$n}"])->withServerVariables(['REMOTE_ADDR' => '192.0.2.50'])
            ->postJson('/api/auth/login', ['username' => 'reader', 'password' => 'wrong-password'])->assertStatus(401);
    }

    fromGallery(['X-Forwarded-For' => '203.0.113.99'])->withServerVariables(['REMOTE_ADDR' => '192.0.2.50'])
        ->postJson('/api/auth/login', ['username' => 'reader', 'password' => 'long-enough'])->assertStatus(429);
});
