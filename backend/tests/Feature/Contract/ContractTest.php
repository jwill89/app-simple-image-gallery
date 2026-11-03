<?php

declare(strict_types=1);

use PHPUnit\Framework\IncompleteTestError;
use Tests\Support\Contract\Replay;

// The Slim app's recorded contract, replayed case by case. A case passes when
// every step answers as recorded (or as divergences.php changes it on purpose);
// it is incomplete while some of its routes aren't ported, after its earlier
// steps have matched.

$divergences = require __DIR__ . '/divergences.php';

dataset('recordings', static function () use ($divergences): Generator {
    foreach (Replay::recordings() as $id => $recording) {
        yield $id => [$recording, $divergences[$id]['steps'] ?? []];
    }
});

test('the recording matches', function (array $recording, array $divergences): void {
    /** @var array<string, mixed> $recording */
    /** @var array<int, array<string, mixed>> $divergences */
    $result = new Replay($recording, $divergences)->run();

    expect($result['problems'])->toBe([]);

    if ($result['waits_on'] !== []) {
        throw new IncompleteTestError("Steps {$result['ran']} of {$result['of']} matched; the rest wait on " . implode(', ', $result['waits_on']) . '.');
    }
})->with('recordings');
