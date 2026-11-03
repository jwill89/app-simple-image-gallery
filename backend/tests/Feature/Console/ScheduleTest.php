<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

// routes/console.php: the nightly ingest, as cron.php ran, and the daily
// clean-up of expired tokens.

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

test('expired tokens are pruned daily', function (): void {
    expect(scheduled()['sanctum:prune-expired --hours=24']->expression ?? null)->toBe('0 0 * * *');
});
