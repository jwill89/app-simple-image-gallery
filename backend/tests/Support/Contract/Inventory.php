<?php

declare(strict_types=1);

namespace Tests\Support\Contract;

use Tests\Support\ContractFixture;

/** The endpoint inventory the recordings index: "METHOD /path" with {param} and {param?} segments. */
final class Inventory
{
    /** Keys in the index that name behavior, not a route. */
    private const array CROSS_CUTTING = ['ANY /{unmatched}', 'OPTIONS /{any}'];

    /** @return list<string> */
    public static function routes(): array
    {
        /** @var array{routes: array<string, list<string>>} $index */
        $index = ContractFixture::recording('index');

        return array_values(array_diff(array_keys($index['routes']), self::CROSS_CUTTING));
    }

    /** A regex matching the request paths (under /api) a route's pattern covers. */
    public static function pattern(string $route): string
    {
        $path = explode(' ', $route, 2)[1];
        $regex = '';
        foreach (explode('/', ltrim($path, '/')) as $segment) {
            $regex .= match (true) {
                str_ends_with($segment, '?}') => '(?:/[^/]+)?',
                str_starts_with($segment, '{') => '/[^/]+',
                default => '/' . preg_quote($segment, '#'),
            };
        }

        return '#^/api' . $regex . '$#';
    }
}
