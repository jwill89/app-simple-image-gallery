<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cloudflare Turnstile, the check that a sign-up or login comes from a person.
 * The page's widget hands the browser a token, sent with the request as
 * `turnstile_token`; Cloudflare says whether it passed. A token passes once,
 * for the action the widget was made for ("login" or "register"), so a token
 * from one form can't be replayed at the other. With no secret configured,
 * nothing is checked (local development, the tests).
 */
final class Turnstile
{
    private const string VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public static function enabled(): bool
    {
        return config()->string('gallery.turnstile.secret') !== '';
    }

    /** The key the page's widget is made with, or null while the check is off. */
    public static function siteKey(): ?string
    {
        $key = config()->string('gallery.turnstile.site_key');

        return self::enabled() && $key !== '' ? $key : null;
    }

    /**
     * Throws unless $token is a pass, for $action, that Cloudflare hasn't seen
     * before. Cloudflare out of reach is 503: no check, no login.
     */
    public function verify(?string $token, string $action, ?string $ip): void
    {
        if (!self::enabled()) {
            return;
        }
        if ($token === null || $token === '') {
            throw new ApiException('CaptchaRequired', 400, 'Please complete the check that you\'re not a bot.');
        }

        try {
            $response = Http::asForm()->timeout(10)->post(self::VERIFY_URL, array_filter([
                'secret' => config()->string('gallery.turnstile.secret'),
                'response' => $token,
                'remoteip' => $ip,
            ], static fn(?string $value): bool => $value !== null && $value !== ''));
        } catch (ConnectionException $e) {
            Log::error('Turnstile unreachable', ['error' => $e->getMessage()]);

            throw new ApiException('CaptchaUnavailable', 503, 'The bot check couldn\'t be reached. Please try again in a moment.');
        }

        $result = $response->json();
        if (!$response->successful() || !is_array($result)) {
            Log::error('Turnstile answered with an error', ['status' => $response->status()]);

            throw new ApiException('CaptchaUnavailable', 503, 'The bot check couldn\'t be reached. Please try again in a moment.');
        }
        // A token carries the action its widget was made with; only Cloudflare's
        // test keys answer with none, which can't be told apart and so passes.
        $issuedFor = is_string($result['action'] ?? null) ? $result['action'] : '';
        if (($result['success'] ?? false) !== true || ($issuedFor !== '' && $issuedFor !== $action)) {
            Log::warning('Turnstile check failed', ['action' => $action, 'errors' => $result['error-codes'] ?? []]);

            throw new ApiException('CaptchaFailed', 403, 'The check that you\'re not a bot didn\'t pass. Please try it again.');
        }
    }
}
