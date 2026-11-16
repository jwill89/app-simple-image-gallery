<?php

declare(strict_types=1);

use Tests\Support\Contract\Replay;

// The recorded API contract (API 4), replayed case by case in API 8's terms. A
// case passes when every step answers as its recording translates
// (Tests\Support\Contract\Api8), or as divergences.php changes it on purpose.
// A case a later API version superseded is left out: the test its entry names
// covers what the app answers instead.

$divergences = require __DIR__ . '/divergences.php';

dataset('recordings', static function () use ($divergences): Generator {
    foreach (Replay::recordings() as $id => $recording) {
        if (isset($divergences[$id]['superseded'])) {
            continue;
        }
        yield $id => [$recording, $divergences[$id]['steps'] ?? []];
    }
});

test('the recording matches', function (array $recording, array $divergences): void {
    /** @var array<string, mixed> $recording */
    /** @var array<int, array<string, mixed>> $divergences */
    expect(new Replay($recording, $divergences)->run())->toBe([]);
})->with('recordings');

test('every divergence names a recording, and every superseded one the test that replaced it', function () use ($divergences): void {
    $recordings = array_keys(Replay::recordings());

    foreach ($divergences as $id => $divergence) {
        expect($recordings)->toContain($id);
        if (isset($divergence['superseded'])) {
            preg_match('#tests/Feature/\S+Test\.php#', $divergence['superseded'], $test);
            expect($test)->toHaveCount(1)
                ->and(base_path($test[0] ?? 'missing'))->toBeFile();
        }
    }
});
