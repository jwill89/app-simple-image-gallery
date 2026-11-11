<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * What the rate limits count a visitor by. An IPv4 address counts as itself. An
 * IPv6 client counts as its /64, since one subscriber is handed a whole /64 (or
 * more) and could otherwise take a fresh address for every attempt.
 */
final class ClientAddress
{
    /** The key for this request's visitor: its IPv4 address, or its IPv6 /64 (`2001:db8:1:2::/64`). */
    public static function key(Request $request): string
    {
        return self::keyFor((string) $request->ip());
    }

    public static function keyFor(string $address): string
    {
        $packed = inet_pton($address);
        if ($packed === false) {
            return $address;
        }

        // An IPv4 address written as IPv6 (::ffff:192.0.2.1) is that IPv4 address.
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            $packed = substr($packed, 12);
        }
        if (strlen($packed) === 4) {
            return (string) inet_ntop($packed);
        }

        return inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }
}
