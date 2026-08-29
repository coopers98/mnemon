<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Mnemon Scheduled Tasks
|--------------------------------------------------------------------------
|
| These run wherever `php artisan schedule:run` (or `schedule:work`) is
| invoked every minute. The Docker Compose stack runs this via a dedicated
| `scheduler` service (`php artisan schedule:work`) — see compose.yaml.
| Outside Docker, wire `schedule:run` into cron. There is no queue worker:
| nothing in this application implements ShouldQueue or dispatches a job.
|
| Every event below declares config('app.timezone') explicitly (exposed as
| APP_TIMEZONE) so the hours mean the same local time regardless of what
| timezone the container/host is running in.
|
*/

// Recalculate confidence scores based on age and source count
// Runs daily at 3 AM local time
Schedule::command('mnemon:decay-confidence')
    ->dailyAt('03:00')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduled.log'));

// Health check: detect stale, orphan, empty, low-confidence pages
// Runs every 6 hours
Schedule::command('mnemon:auto-lint')
    ->everySixHours()
    ->timezone(config('app.timezone'))
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduled.log'));

// Identify wiki pages with pending drawer updates
// Runs every 4 hours
Schedule::command('mnemon:auto-compile-stale')
    ->everyFourHours()
    ->timezone(config('app.timezone'))
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduled.log'));

// Prune old revisions and low-retention drawers
// Runs weekly on Sundays at 4 AM local time
Schedule::command('mnemon:apply-retention --force')
    ->weeklyOn(0, '04:00')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduled.log'));
