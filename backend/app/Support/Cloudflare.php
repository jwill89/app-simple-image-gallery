<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The gallery is served through Cloudflare, so every request reaches Apache
 * from one of Cloudflare's addresses, with the visitor's in X-Forwarded-For.
 * Trusting these ranges (bootstrap/app.php) makes $request->ip() the visitor,
 * which the rate limits, the login and sign-up limits and Turnstile all count
 * by; trusted for everyone, every visitor behind one Cloudflare server would
 * share one limit. A request from anywhere else keeps its own address, and
 * its X-Forwarded-For is ignored, so the header can't be forged around them.
 */
final class Cloudflare
{
    /** cloudflare.com/ips-v4 and /ips-v6, as published on 2026-11-03. Update them if Cloudflare does. */
    public const array RANGES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
        '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
        '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
        '2a06:98c0::/29', '2c0f:f248::/32',
    ];
}
