<?php

declare(strict_types=1);

namespace Tests\Support\Contract;

/**
 * Recorded values against actual ones, with the placeholders in the
 * recordings' README: <token>, <seconds>, <now> and dupes-<today>.json match
 * any value of their kind, and ssim compares to three decimal places.
 * Objects compare without regard to key order, arrays in order.
 */
final class Matcher
{
    public static function header(?string $expected, ?string $actual): bool
    {
        if ($expected === '<seconds>') {
            return $actual !== null && ctype_digit($actual) && (int) $actual > 0;
        }

        return $expected === $actual;
    }

    /** Cache-Control directives in any order: Symfony sorts them ("max-age=300, public"). */
    public static function cacheControl(?string $expected, ?string $actual): bool
    {
        $directives = static function (?string $value): ?array {
            if ($value === null) {
                return null;
            }
            $list = array_map(trim(...), explode(',', $value));
            sort($list);

            return $list;
        };

        return $directives($expected) === $directives($actual);
    }

    /** null when they match, else where and how they differ. */
    public static function json(mixed $expected, mixed $actual, string $at = '$', ?string $key = null): ?string
    {
        if (is_array($expected)) {
            if (!is_array($actual)) {
                return "at {$at}: " . self::show($actual) . ', expected ' . (array_is_list($expected) ? 'a list' : 'an object');
            }
            $missing = array_diff_key($expected, $actual);
            $extra = array_diff_key($actual, $expected);
            if ($missing !== [] || $extra !== []) {
                return "at {$at}: keys " . self::show(array_keys($actual)) . ', expected ' . self::show(array_keys($expected));
            }
            foreach ($expected as $name => $value) {
                $mismatch = self::json($value, $actual[$name], "{$at}.{$name}", is_string($name) ? $name : $key);
                if ($mismatch !== null) {
                    return $mismatch;
                }
            }

            return null;
        }

        return self::value($expected, $actual, $key) ? null : "at {$at}: " . self::show($actual) . ', expected ' . self::show($expected);
    }

    private static function value(mixed $expected, mixed $actual, ?string $key): bool
    {
        return match (true) {
            $expected === '<token>' => is_string($actual) && preg_match('/^[0-9a-f]{64}$/', $actual) === 1,
            $expected === '<seconds>' => (is_int($actual) || is_float($actual)) && $actual >= 0,
            $expected === '<now>' => self::isNow($actual),
            $expected === 'dupes-<today>.json' => $actual === 'dupes-' . now()->format('Y-m-d') . '.json',
            $key === 'ssim' && is_float($expected) => is_float($actual) && abs($expected - $actual) < 0.001,
            default => $expected === $actual,
        };
    }

    private static function isNow(mixed $actual): bool
    {
        $time = match (true) {
            is_int($actual) => $actual,
            is_string($actual) => strtotime($actual),
            default => false,
        };

        return $time !== false && abs($time - time()) < 3600;
    }

    private static function show(mixed $value): string
    {
        return mb_strimwidth((string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 300, '…');
    }
}
