<?php

declare(strict_types=1);

namespace App\Services\Danbooru;

use App\Exceptions\ApiException;

/**
 * Why a Danbooru lookup failed, when it wasn't simply "no match". Reported as
 * "not found" these would send you looking for the wrong problem, and look
 * the same on every item.
 */
enum DanbooruFailure
{
    /** HTTP 401 or 403 from the API: the credentials, or the API key's IP allowlist. */
    case Auth;

    /**
     * HTTP 403 from the firewall in front of the API (Cloudflare), which
     * refuses the server's requests before the API key is even checked. The
     * API answers in JSON; the firewall's block page is HTML.
     */
    case Blocked;

    /** HTTP 429. */
    case RateLimit;

    /** No response at all: DNS, outbound HTTPS, the TLS bundle. */
    case Network;

    /** HTTP 5xx. */
    case Upstream;

    /**
     * The API error. Never 502 or 504: the site sits behind Cloudflare, which
     * replaces gateway-class responses with its own HTML page, so the message
     * would never reach the browser. 429, 500 and 503 pass through untouched.
     *
     * @param ?string $ray Cloudflare's Ray ID for a blocked request, which Danbooru's admins can look up.
     */
    public function exception(?string $ray = null): ApiException
    {
        return match ($this) {
            self::Auth => new ApiException(
                'DanbooruAuthFailed',
                500,
                'Danbooru rejected the request. Check DANBOORU_LOGIN and DANBOORU_API_KEY '
                . 'in the server .env, and the IP allowlist on the API key — keys are '
                . 'revoked when the account password changes, and a new outbound address '
                . '(enabling IPv6 gives the server one) is refused until it is allowlisted.',
            ),
            self::Blocked => new ApiException(
                'DanbooruBlocked',
                503,
                'Danbooru\'s firewall (Cloudflare) blocked this server\'s request before it reached '
                . 'the API, so the credentials were never checked. ' . self::unblockAdvice($ray),
            ),
            self::RateLimit => new ApiException('DanbooruRateLimited', 429, 'Danbooru is rate-limiting this account. Wait a minute and try again.'),
            self::Network => new ApiException(
                'DanbooruUnreachable',
                503,
                'Could not reach Danbooru from the server — DNS, outbound HTTPS or the TLS certificate bundle.',
            ),
            self::Upstream => new ApiException('DanbooruUnavailable', 503, 'Danbooru returned an error. Try again shortly.'),
        };
    }

    /**
     * What to do about a block. Danbooru asks API clients to name the account
     * in the User-Agent and blocks those that don't, so without a user ID set
     * that is the fix; with one, the block is Danbooru's to lift.
     */
    private static function unblockAdvice(?string $ray): string
    {
        if (config()->string('gallery.danbooru.user_id') === '') {
            return 'Set DANBOORU_USER_ID in the server .env to the account\'s numeric user ID, '
                . 'which Danbooru asks API clients to name in their User-Agent.';
        }

        return 'Ask Danbooru to allow the server\'s address' . ($ray !== null ? ", quoting Cloudflare Ray ID {$ray}." : '.');
    }
}
