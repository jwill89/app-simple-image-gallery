<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('sets the SQLite PRAGMAs the Slim backend set on every connection', function (string $pragma, int $expected): void {
    expect(DB::scalar("pragma {$pragma}"))->toBe($expected);
})->with([
    'foreign keys on' => ['foreign_keys', 1],
    '5 s busy timeout' => ['busy_timeout', 5000],
    'synchronous NORMAL' => ['synchronous', 1],
    '20 MB page cache' => ['cache_size', -20000],
    'temp tables in memory' => ['temp_store', 2],
]);
