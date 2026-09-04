<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ScheduleTimezoneTest extends TestCase
{
    /**
     * There used to be a sibling test here asserting every scheduled event's
     * ->timezone is non-null. It could not actually fail on this Laravel
     * version: the framework's own Schedule::class singleton is constructed
     * with config('app.timezone') as its default timezone
     * (Illuminate\Foundation\Console\Kernel::scheduleTimezone()), and every
     * event inherits that default the instant it is created — whether or not
     * routes/console.php ever calls ->timezone() itself. Deleting every
     * ->timezone(...) call in routes/console.php left that test green, so it
     * was removed rather than kept as decorative coverage.
     *
     * The real defect it was trying to guard was the literal hour values:
     * they were chosen as raw UTC instants ("08:00" commented "UTC = 3 AM
     * CT") rather than local hours. Once config('app.timezone') is genuinely
     * wired to APP_TIMEZONE, those same literal hours stop meaning 3am/4am
     * for any operator who sets a non-UTC timezone. This test below is the
     * one that actually guards that: it asserts the cron hour fields
     * themselves encode the early-morning local intent the comments
     * describe, which a UTC-instant value would not.
     */
    public function test_daily_and_weekly_commands_run_at_the_documented_local_hour(): void
    {
        $events = app(Schedule::class)->events();

        $decay = collect($events)->first(
            fn ($event) => str_contains($event->command, 'mnemon:decay-confidence')
        );
        $retention = collect($events)->first(
            fn ($event) => str_contains($event->command, 'mnemon:apply-retention')
        );

        $this->assertNotNull($decay, 'expected mnemon:decay-confidence to be scheduled');
        $this->assertNotNull($retention, 'expected mnemon:apply-retention to be scheduled');

        // "Runs daily at 3 AM" — the cron hour field, not a UTC instant that
        // means something different once the timezone is honoured.
        $this->assertSame(
            '3',
            explode(' ', $decay->expression)[1],
            "decay-confidence cron [{$decay->expression}] does not run at the documented 3am local hour"
        );

        // "Runs weekly on Sundays at 4 AM"
        $this->assertSame(
            '4',
            explode(' ', $retention->expression)[1],
            "apply-retention cron [{$retention->expression}] does not run at the documented 4am local hour"
        );
    }

    public function test_the_schedule_only_runs_work_that_changes_something(): void
    {
        // mnemon:auto-lint and mnemon:auto-compile-stale do not lint or compile
        // anything — each builds a JSON report and prints it. Scheduled, that
        // report went to storage/logs/scheduled.log, which nothing in this
        // codebase reads. Four hours of "automation" that produced a file nobody
        // opens is worse than none, because it reads like the wiki is being kept
        // current when nothing is keeping it current.
        //
        // The information itself is not lost: palace_wake_up already returns
        // pending_update_pages to every agent at session start, and wiki_lint is
        // an MCP tool an agent can call on demand. Both commands remain for
        // manual use.
        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($event) => $event->command)
            ->implode(' ');

        $this->assertStringNotContainsString('mnemon:auto-lint', $commands);
        $this->assertStringNotContainsString('mnemon:auto-compile-stale', $commands);

        // The two that genuinely mutate stay scheduled.
        $this->assertStringContainsString('mnemon:decay-confidence', $commands);
        $this->assertStringContainsString('mnemon:apply-retention', $commands);
    }
}
