<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Mnemon Scheduled Tasks
|--------------------------------------------------------------------------
|
| Queue worker + scheduler must be running on Forge:
|   - Daemon: php artisan queue:work --sleep=3 --daemon --quiet
|   - Scheduler: php artisan schedule:run (every minute via Forge cron)
|
*/

// Recalculate confidence scores based on age and source count
// Runs daily at 3 AM CT
Schedule::command('mnemon:decay-confidence')
    ->dailyAt('08:00') // UTC = 3 AM CT (CDT)
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduled.log'));

// Health check: detect stale, orphan, empty, low-confidence pages
// Runs every 6 hours
Schedule::command('mnemon:auto-lint')
    ->everySixHours()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduled.log'));

// Identify wiki pages with pending drawer updates
// Runs every 4 hours
Schedule::command('mnemon:auto-compile-stale')
    ->everyFourHours()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduled.log'));

// Prune old revisions and low-retention drawers
// Runs weekly on Sundays at 4 AM CT
Schedule::command('mnemon:apply-retention --force')
    ->weeklyOn(0, '09:00') // UTC = 4 AM CT (CDT)
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduled.log'));
