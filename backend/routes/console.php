<?php

declare(strict_types=1);

use App\Console\Commands\IngestMedia;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Schedule (`php artisan schedule:run`, every minute from cron)
|--------------------------------------------------------------------------
|
| Times are in app.schedule_timezone (SCHEDULE_TIMEZONE), set to the
| droplet's own so the ingest runs at its midnight.
|
*/

// The files dropped into the media folder during the day. A run that takes
// past the next midnight isn't started twice.
Schedule::command(IngestMedia::class)->dailyAt('00:00')->withoutOverlapping();
