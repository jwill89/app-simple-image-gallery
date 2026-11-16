<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\HttpFoundation\Response;

/**
 * The Content-Security-Policy each kind of response is sent with.
 *
 * A page loads its scripts, styles and everything else from the gallery's own
 * origin, and nothing inline unless it carries the response's nonce. The SPA
 * shell's own tags carry it, and so do the tags the page adds as it runs
 * (Vite's preloads, Turnstile's script) and any script Cloudflare injects.
 * Cloudflare Turnstile, the bot check on the sign-in and sign-up pages, is the
 * one third party: its script and its frame come from challenges.cloudflare.com.
 * No page may be framed, by anyone.
 *
 * Anything that isn't a page, an API answer above all, may load, run or be
 * framed by nothing (NONE): SecurityHeaders sends that wherever a response
 * hasn't set a policy of its own.
 *
 * With gallery.csp_report_only on, every policy goes out as
 * Content-Security-Policy-Report-Only: the browser reports what the policy
 * would refuse, and refuses nothing.
 */
final class ContentSecurityPolicy
{
    /** Cloudflare Turnstile's origin, for its script and the frame it draws the check in. */
    public const string TURNSTILE = 'https://challenges.cloudflare.com';

    /** The policy of a response that is not a page. */
    public const string NONE = "default-src 'none'; frame-ancestors 'none'";

    /** The directives every page starts from. */
    private const array PAGE = [
        'default-src' => ["'self'"],
        'script-src' => ["'self'"],
        'style-src' => ["'self'"],
        'object-src' => ["'none'"],
        'base-uri' => ["'none'"],
        'form-action' => ["'self'"],
        'frame-ancestors' => ["'none'"],
    ];

    /** A fresh nonce for one response: 128 random bits, base64-encoded. */
    public static function nonce(): string
    {
        return base64_encode(random_bytes(16));
    }

    /** The SPA's policy, admitting the scripts and styles that carry $nonce, and Turnstile. */
    public static function spa(string $nonce): string
    {
        return self::page([
            'script-src' => ["'self'", "'nonce-{$nonce}'", self::TURNSTILE],
            'style-src' => ["'self'", "'nonce-{$nonce}'"],
            'frame-src' => [self::TURNSTILE],
        ]);
    }

    /**
     * The API reference's policy: Swagger UI's script and stylesheet are files
     * on the gallery's own origin, and its stylesheet draws its icons as data:
     * images.
     */
    public static function apiReference(): string
    {
        return self::page(['img-src' => ["'self'", 'data:']]);
    }

    /** The header a policy goes out in: enforced, or report-only while gallery.csp_report_only is on. */
    public static function header(): string
    {
        return config()->boolean('gallery.csp_report_only')
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';
    }

    /** Sends $policy with $response, in the header header() names. */
    public static function apply(Response $response, string $policy): void
    {
        $response->headers->set(self::header(), $policy);
    }

    /** Whether $response already has a policy, enforced or report-only. */
    public static function isSet(Response $response): bool
    {
        return $response->headers->has('Content-Security-Policy')
            || $response->headers->has('Content-Security-Policy-Report-Only');
    }

    /** @param array<string, list<string>> $directives */
    private static function page(array $directives): string
    {
        $policy = array_merge(self::PAGE, $directives);

        return implode('; ', array_map(
            static fn(string $name, array $sources): string => $name . ' ' . implode(' ', $sources),
            array_keys($policy),
            $policy,
        ));
    }
}
