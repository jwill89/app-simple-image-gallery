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
    /** HTTP 401 or 403: the credentials, or the API key's IP allowlist. */
    case Auth;

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
     */
    public function exception(): ApiException
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
            self::RateLimit => new ApiException('DanbooruRateLimited', 429, 'Danbooru is rate-limiting this account. Wait a minute and try again.'),
            self::Network => new ApiException(
                'DanbooruUnreachable',
                503,
                'Could not reach Danbooru from the server — DNS, outbound HTTPS or the TLS certificate bundle.',
            ),
            self::Upstream => new ApiException('DanbooruUnavailable', 503, 'Danbooru returned an error. Try again shortly.'),
        };
    }
}
