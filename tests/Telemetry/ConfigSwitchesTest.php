<?php

namespace SimpleDataLogs\Laravel\Tests\Telemetry;

use SimpleDataLogs\Laravel\Facades\SimpleDataLogs;
use SimpleDataLogs\Laravel\Tests\Telemetry\Fixtures\QueryingJob;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * The switches: telemetry as a whole, the key it needs, and one per signal.
 */
class ConfigSwitchesTest extends TelemetryTestCase
{
    // Config set by the test's environment method, applied again after the base environment.
    /** @var array<string, mixed> */
    private array $configOverrides = [];

    /**
     * The base environment, then the test's own switches on top.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app["config"]->set($this->configOverrides);
    }

    /**
     * Sets config now and remembers it for after the base environment, whichever runs first.
     *
     * @param array<string, mixed> $configValues
     */
    private function override($app, array $configValues): void
    {
        $this->configOverrides = array_merge($this->configOverrides, $configValues);
        $app["config"]->set($configValues);
    }

    /**
     * SIMPLEDATALOGS_TELEMETRY=false.
     */
    protected function telemetryOff($app): void
    {
        $this->override($app, ["simpledatalogs.telemetry.enabled" => false]);
    }

    /**
     * No ingest key.
     */
    protected function withoutKey($app): void
    {
        $this->override($app, ["simpledatalogs.key" => ""]);
    }

    /**
     * Every child signal off, requests on.
     */
    protected function onlyRequests($app): void
    {
        $this->override($app, [
            "simpledatalogs.telemetry.queries" => false,
            "simpledatalogs.telemetry.http" => false,
            "simpledatalogs.telemetry.cache" => false,
            "simpledatalogs.telemetry.mail" => false,
            "simpledatalogs.telemetry.exceptions" => false,
        ]);
    }

    /**
     * Requests off, exceptions on.
     */
    protected function withoutRequests($app): void
    {
        $this->override($app, ["simpledatalogs.telemetry.requests" => false]);
    }

    /**
     * Jobs off.
     */
    protected function withoutJobs($app): void
    {
        $this->override($app, ["simpledatalogs.telemetry.jobs" => false]);
    }

    /**
     * An outbox folder that can never be created.
     */
    protected function unwritableOutbox($app): void
    {
        $this->override($app, ["simpledatalogs.outbox_path" => "/proc/simpledatalogs-unwritable"]);
    }

    /**
     * The routes the tests call.
     */
    protected function defineRoutes($router): void
    {
        $router->get("/busy", function () {
            Http::fake(["api.example.test/*" => Http::response("", 200)]);

            DB::select("select 1");
            Cache::get("settings");
            Http::get("https://api.example.test/ping");
            report(new \RuntimeException("Handled along the way"));
            Log::channel("partner_api")->info("/catalog/products 1 1");

            return SimpleDataLogs::span("render", fn () => "busy");
        });

        $router->get("/broken", fn () => throw new \RuntimeException("Broken"));

        $router->get("/sync-job", function () {
            QueryingJob::dispatchSync();

            return "ran";
        });
    }

    #[DefineEnvironment("telemetryOff")]
    public function test_telemetry_false_sends_nothing_and_the_app_works_the_same(): void
    {
        $this->get("/busy")->assertOk()->assertSee("busy");

        $this->assertSame([], array_filter($this->outboxLines(), fn (array $line): bool => isset($line["kind"]) || isset($line["trace_id"])));
    }

    #[DefineEnvironment("withoutKey")]
    public function test_without_a_key_telemetry_stays_off(): void
    {
        $this->get("/busy")->assertOk();

        $this->assertSame([], $this->bundles("request"));
    }

    #[DefineEnvironment("onlyRequests")]
    public function test_each_child_signal_has_its_own_switch(): void
    {
        $this->get("/busy")->assertOk();

        $bundle = $this->onlyBundle("request");

        // Only the custom span is left, and no exception was recorded.
        $this->assertSame(["span"], array_column($bundle["spans"], "kind"));
        $this->assertSame(["spans"], array_keys($bundle["summary"]));
        $this->assertArrayNotHasKey("exceptions", $bundle);
    }

    #[DefineEnvironment("withoutRequests")]
    public function test_with_requests_off_exceptions_are_sent_on_their_own(): void
    {
        $this->get("/broken")->assertStatus(500);

        $this->assertSame([], $this->bundles("request"));
        $this->assertSame("Broken", $this->onlyBundle("exception")["message"]);
    }

    #[DefineEnvironment("withoutJobs")]
    public function test_with_jobs_off_no_job_bundle_is_written(): void
    {
        $this->get("/sync-job")->assertOk();

        $this->assertSame([], $this->bundles("job"));

        // The job's query then belongs to the request around it.
        $this->assertCount(1, $this->children($this->onlyBundle("request"), "query"));
    }

    #[DefineEnvironment("unwritableOutbox")]
    public function test_an_unwritable_outbox_never_breaks_the_app(): void
    {
        $this->get("/busy")->assertOk()->assertSee("busy");
        $this->get("/broken")->assertStatus(500);

        report(new \RuntimeException("Outside any request"));

        $this->assertDirectoryDoesNotExist("/proc/simpledatalogs-unwritable");
    }
}
