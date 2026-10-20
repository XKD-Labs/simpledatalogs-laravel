<?php

namespace SimpleDataLogs\Laravel\Tests\Telemetry;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Foundation\Testing\WithConsoleEvents;

/**
 * Artisan commands: name, exit code and duration; arguments never, and the ship command and the scheduler are skipped.
 */
class CommandTelemetryTest extends TelemetryTestCase
{
    // Laravel only raises command events in tests with this trait, as it always does outside tests.
    use WithConsoleEvents;

    /**
     * Registers the commands the tests run.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::command("reports:build {token}", function (): int {
            DB::select("select 1");

            return 0;
        });

        Artisan::command("reports:broken", fn (): int => 3);
    }

    public function test_a_command_sends_its_name_exit_code_and_children_but_never_its_arguments(): void
    {
        $this->artisan("reports:build", ["token" => "zz-secret-token"])->assertExitCode(0);

        $bundle = $this->onlyBundle("command");

        $this->assertSame("reports:build", $bundle["name"]);
        $this->assertSame("ok", $bundle["status"]);
        $this->assertSame(0, $bundle["props"]["exit_code"]);
        $this->assertCount(1, $this->children($bundle, "query"));
        $this->assertStringNotContainsString("zz-secret-token", $this->outboxText());
    }

    public function test_a_non_zero_exit_code_is_an_error(): void
    {
        $this->artisan("reports:broken")->assertExitCode(3);

        $bundle = $this->onlyBundle("command");

        $this->assertSame("error", $bundle["status"]);
        $this->assertSame(3, $bundle["props"]["exit_code"]);
    }

    public function test_the_ship_command_and_the_scheduler_are_never_recorded(): void
    {
        $this->artisan("simpledatalogs:ship", ["--once" => true])->assertExitCode(0);
        $this->artisan("schedule:run")->assertExitCode(0);

        $this->assertSame([], $this->bundles("command"));
    }
}
