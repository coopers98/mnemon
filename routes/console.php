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

// mnemon:auto-lint and mnemon:auto-compile-stale used to be scheduled here,
// every six and four hours. Neither lints nor compiles anything — each builds a
// JSON report and prints it, and scheduled, that print went to
// storage/logs/scheduled.log, which nothing in this codebase reads.
//
// Removed rather than rewired, because the information already reaches the only
// thing that can act on it: palace_wake_up returns pending_update_pages to every
// agent at session start, and wiki_lint is an MCP tool an agent can call. A
// scheduled job writing to an unread file is worse than no job, because it reads
// like the wiki is being kept current when nothing is keeping it current —
// compilation is agent-triggered and always has been.
//
// Both commands remain for manual use.

// Prune old revisions and low-retention drawers
// Runs weekly on Sundays at 4 AM local time
Schedule::command('mnemon:apply-retention --force')
    ->weeklyOn(0, '04:00')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduled.log'));
