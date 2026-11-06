<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Auth\SessionGuard;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\call;

/**
 * A browser on the gallery's own page, for the tests of signing in and out:
 * it sends the cookies the server last set, until they expire, and nothing
 * else says who it is. Each request starts with no account in the auth guard,
 * as a request to the real server does, so two browsers in one test are two
 * strangers. The other tests act as an account directly (asAccount() in
 * Pest.php).
 */
final class TestBrowser
{
    /** @var array<string, string> The cookies this browser holds, decrypted. */
    private array $cookies = [];

    /** @var array<string, int> When each cookie expires, as a timestamp; 0 when the browser closes. */
    private array $expires = [];

    public function __construct()
    {
        config(['gallery.allowed_origins' => [GALLERY_ORIGIN], 'cors.allowed_origins' => [GALLERY_ORIGIN]]);
    }

    /**
     * @param array<string, mixed> $body
     * @return TestResponse<Response>
     */
    public function send(string $method, string $uri, array $body = []): TestResponse
    {
        app('auth')->forgetGuards();
        foreach ($this->expires as $name => $at) {
            if ($at !== 0 && $at <= now()->getTimestamp()) {
                $this->forget($name);
            }
        }

        $encrypter = app('encrypter');
        $cookies = [];
        foreach ($this->cookies as $name => $value) {
            $cookies[$name] = $encrypter->encrypt(CookieValuePrefix::create($name, $encrypter->getKey()) . $value, false);
        }

        $response = call($method, $uri, [], $cookies, [], [
            'HTTP_ORIGIN' => GALLERY_ORIGIN,
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ], $body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR));

        foreach ($response->headers->getCookies() as $cookie) {
            $value = (string) $cookie->getValue();
            if ($cookie->isCleared() || $value === '') {
                $this->forget($cookie->getName());
            } else {
                $this->cookies[$cookie->getName()] = CookieValuePrefix::remove((string) $encrypter->decrypt($value, false));
                $this->expires[$cookie->getName()] = $cookie->getExpiresTime();
            }
        }

        return $response;
    }

    /** Hold a cookie the server never set, as a forged or stale one. */
    public function setCookie(string $name, string $value): void
    {
        $this->cookies[$name] = $value;
        $this->expires[$name] = 0;
    }

    public function hasCookie(string $name): bool
    {
        return isset($this->cookies[$name]);
    }

    /** The browser closes and opens again: the cookies that lasted until then are gone. */
    public function restart(): void
    {
        foreach ($this->expires as $name => $at) {
            if ($at === 0) {
                $this->forget($name);
            }
        }
    }

    /** The name of the web guard's remember cookie. */
    public static function rememberCookie(): string
    {
        return 'remember_web_' . sha1(SessionGuard::class);
    }

    private function forget(string $name): void
    {
        unset($this->cookies[$name], $this->expires[$name]);
    }
}
