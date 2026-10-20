<?php

namespace SimpleDataLogs\Laravel\Tests\Telemetry;

use SimpleDataLogs\Laravel\Monolog\AddTraceProcessor;

use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * The Monolog processor stamps the running trace on every record, and forwarded lines carry it as top-level fields.
 */
class TraceProcessorTest extends TelemetryTestCase
{
    /**
     * The routes the tests call.
     */
    protected function defineRoutes($router): void
    {
        $router->get("/sync", function () {
            Log::channel("partner_api")->info("/catalog/products 1 1");

            return "synced";
        });
    }

    /**
     * Turns the log processor off.
     */
    protected function withoutLogStamps($app): void
    {
        $app["config"]->set("simpledatalogs.telemetry.logs", false);
    }

    /**
     * The forwarded log lines in the outbox (lines without a kind).
     *
     * @return array<int, array<string, mixed>>
     */
    private function logLines(): array
    {
        return array_values(array_filter($this->outboxLines(), fn (array $line): bool => ! isset($line["kind"])));
    }

    public function test_a_log_line_inside_a_request_carries_its_trace_and_span(): void
    {
        $this->get("/sync")->assertOk();

        $request = $this->onlyBundle("request");
        [$logLine] = $this->logLines();

        $this->assertSame($request["trace_id"], $logLine["trace_id"]);
        $this->assertSame($request["span_id"], $logLine["span_id"]);
        $this->assertArrayNotHasKey("extra", $logLine["props"]);
        $this->assertSame("partner_api", $logLine["props"]["channel"]);
    }

    public function test_a_log_line_outside_any_entry_point_is_unchanged(): void
    {
        Log::channel("partner_api")->info("/catalog/products 1 1");

        [$logLine] = $this->logLines();

        $this->assertArrayNotHasKey("trace_id", $logLine);
        $this->assertArrayNotHasKey("span_id", $logLine);
    }

    public function test_every_configured_channel_gets_the_processor_tap_once(): void
    {
        foreach (array_keys(config("logging.channels")) as $channelName) {
            $this->assertSame(1, count(array_keys(config("logging.channels.{$channelName}.tap"), AddTraceProcessor::class)), $channelName);
        }
    }

    #[DefineEnvironment("withoutLogStamps")]
    public function test_the_logs_switch_turns_the_stamps_off(): void
    {
        $this->get("/sync")->assertOk();

        [$logLine] = $this->logLines();

        $this->assertArrayNotHasKey("trace_id", $logLine);
        $this->assertNotContains(AddTraceProcessor::class, config("logging.channels.partner_api.tap"));
    }
}
