<?php

namespace SimpleDataLogs\Laravel\Tests\Telemetry;

use SimpleDataLogs\Laravel\Telemetry\Listeners\ScheduledTaskListener;

use Illuminate\Support\Facades\DB;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;

/**
 * Scheduled tasks: command line, expression, `interval_s` from the cron table, duration and status; skips and failures too.
 */
class ScheduledTaskTelemetryTest extends TelemetryTestCase
{
    public function test_a_task_sends_its_command_line_expression_and_interval(): void
    {
        $task = $this->app->make(Schedule::class)->command("reports:send --daily")->everyFiveMinutes();

        $this->app["events"]->dispatch(new ScheduledTaskStarting($task));
        DB::select("select 1");
        $this->app["events"]->dispatch(new ScheduledTaskFinished($task, 0.01));

        $bundle = $this->onlyBundle("task");

        $this->assertSame("reports:send --daily", $bundle["name"]);
        $this->assertSame("ok", $bundle["status"]);
        $this->assertSame("*/5 * * * *", $bundle["props"]["expression"]);
        $this->assertSame(300, $bundle["props"]["interval_s"]);
        $this->assertCount(1, $this->children($bundle, "query"));
    }

    public function test_a_failing_task_run_by_the_scheduler_is_an_error_and_schedule_run_itself_is_not_recorded(): void
    {
        $this->app->make(Schedule::class)->call(fn () => throw new \RuntimeException("Nightly export broke"))->everyMinute()->name("nightly-export");

        $this->artisan("schedule:run");

        $bundle = $this->onlyBundle("task");

        $this->assertSame("nightly-export", $bundle["name"]);
        $this->assertSame("error", $bundle["status"]);
        $this->assertSame(60, $bundle["props"]["interval_s"]);
        $this->assertSame("Nightly export broke", $bundle["exceptions"][0]["message"]);
        $this->assertFalse($bundle["exceptions"][0]["handled"]);
        $this->assertSame([], $this->bundles("command"));
        $this->assertSame([], $this->bundles("exception"));
    }

    public function test_a_skipped_run_is_an_instant_bundle_marked_skipped(): void
    {
        $this->app->make(Schedule::class)->call(fn () => null)->everyMinute()->name("only-on-mondays")->when(fn () => false);

        $this->artisan("schedule:run");

        $bundle = $this->onlyBundle("task");

        $this->assertSame("only-on-mondays", $bundle["name"]);
        $this->assertTrue($bundle["props"]["skipped"]);
        $this->assertSame("ok", $bundle["status"]);
    }

    public function test_the_interval_comes_from_the_cron_expression_by_a_fixed_table(): void
    {
        $expectedIntervals = [
            "* * * * *" => 60,
            "*/5 * * * *" => 300,
            "0 * * * *" => 3600,
            "15,45 * * * *" => 1800,
            "0 */6 * * *" => 21600,
            "0 0 * * *" => 86400,
            "0 9,17 * * *" => 43200,
            "0 0 * * 0" => 604800,
            "0 9 * * 1-5" => 86400,
            "0 0 1 * *" => 2592000,
            "0 0 1 */3 *" => 7776000,
            "0 0 1 1 *" => 31536000,
            "0 0 1 1,7 *" => 15768000,
            "@weird" => null,
        ];

        $schedule = $this->app->make(Schedule::class);

        foreach ($expectedIntervals as $expression => $expectedSeconds) {
            $this->assertSame($expectedSeconds, ScheduledTaskListener::intervalSeconds($schedule->command("inspire")->cron($expression)), $expression);
        }

        $this->assertSame(10, ScheduledTaskListener::intervalSeconds($schedule->command("inspire")->everyTenSeconds()));
    }

    public function test_the_task_name_drops_the_php_binary_and_artisan(): void
    {
        $schedule = $this->app->make(Schedule::class);

        $this->assertSame("inspire --quiet", ScheduledTaskListener::taskName($schedule->command("inspire --quiet")));
        $this->assertSame("/usr/bin/backup.sh", ScheduledTaskListener::taskName($schedule->exec("/usr/bin/backup.sh")));
        $this->assertSame("Callback", ScheduledTaskListener::taskName($schedule->call(fn () => null)));
    }
}
