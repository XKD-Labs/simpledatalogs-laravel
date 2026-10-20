<?php

namespace SimpleDataLogs\Laravel\Tests\Telemetry;

use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * Sampling through config: summary-only roots, detail for slow and errored ones, and entry-point sampling.
 */
class SamplingTelemetryTest extends TelemetryTestCase
{
    /**
     * The routes the tests call.
     */
    protected function defineRoutes($router): void
    {
        $router->get("/fast", function () {
            DB::select("select 1");

            return "fast";
        });

        $router->get("/broken", function () {
            DB::select("select 1");

            throw new \RuntimeException("Broken");
        });
    }

    /**
     * Detail almost never by chance, so only slow and errored roots send children.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app["config"]->set("simpledatalogs.telemetry.detail_every", PHP_INT_MAX);
    }

    /**
     * Every request counts as slow.
     */
    protected function everythingSlow($app): void
    {
        $app["config"]->set("simpledatalogs.telemetry.slow_request_ms", -1);
    }

    /**
     * Keeps almost no plain roots.
     */
    protected function tinySampleRate($app): void
    {
        $app["config"]->set("simpledatalogs.telemetry.sample_rate", 0.000001);
    }

    public function test_a_plain_root_sends_its_summary_without_children(): void
    {
        $this->get("/fast")->assertOk();

        $bundle = $this->onlyBundle("request");

        $this->assertArrayNotHasKey("spans", $bundle);
        $this->assertSame(1, $bundle["summary"]["queries"]["count"]);
    }

    #[DefineEnvironment("everythingSlow")]
    public function test_a_slow_root_sends_its_children(): void
    {
        $this->get("/fast")->assertOk();

        $this->assertCount(1, $this->onlyBundle("request")["spans"]);
    }

    public function test_an_errored_root_sends_its_children(): void
    {
        $this->get("/broken")->assertStatus(500);

        $this->assertCount(1, $this->onlyBundle("request")["spans"]);
    }

    #[DefineEnvironment("tinySampleRate")]
    public function test_entry_point_sampling_drops_plain_roots_and_keeps_errored_ones_unweighted(): void
    {
        $this->get("/fast")->assertOk();
        $this->get("/broken")->assertStatus(500);

        $bundle = $this->onlyBundle("request");

        $this->assertSame("error", $bundle["status"]);
        $this->assertArrayNotHasKey("sample_weight", $bundle);
    }
}
