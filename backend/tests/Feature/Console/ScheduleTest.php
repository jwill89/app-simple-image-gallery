<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

// routes/console.php: the nightly ingest. Expired sessions need no schedule:
// the file driver sweeps them as requests arrive (session.lottery).

/** @return array<string, Event> */
function scheduled(): array
{
    $events = [];
    foreach (app(Schedule::class)->events() as $event) {
        $events[(string) preg_replace('/^.*artisan[\'"]? /', '', (string) $event->command)] = $event;
    }

    return $events;
}

test('the ingest runs at midnight, never two at once', function (): void {
    $ingest = scheduled()['gallery:ingest'] ?? null;

    expect($ingest)->not->toBeNull()
        ->and($ingest?->expression)->toBe('0 0 * * *')
        ->and($ingest?->withoutOverlapping)->toBeTrue();
});

test('nothing else is scheduled', function (): void {
    expect(array_keys(scheduled()))->toBe(['gallery:ingest']);
});
