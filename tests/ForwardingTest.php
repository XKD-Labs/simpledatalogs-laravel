<?php

namespace SimpleDataLogs\Laravel\Tests;

use SimpleDataLogs\Laravel\Outbox;
use SimpleDataLogs\Laravel\Shipper;
use SimpleDataLogs\Laravel\Transport;

use Illuminate\Support\Facades\Log;

/**
 * The whole path in a Laravel app: a tapped channel queues ingest API lines, and the ship command sends them through the transport.
 */
class ForwardingTest extends TestCase
{
    /**
     * The lines waiting in the outbox, decoded.
     *
     * @return array<int, array<string, mixed>>
     */
    private function queuedLines(): array
    {
        $outbox = $this->app->make(Outbox::class);
        $lines = [];

        foreach (glob($this->outboxPath . "/pending/*.ndjson") ?: [] as $pendingPath) {
            foreach ($outbox->lines($pendingPath) as $line) {
                $lines[] = json_decode($line, true);
            }
        }

        return $lines;
    }

    public function test_a_tapped_channel_queues_lines_with_ids_times_and_typed_message_fields(): void
    {
        Log::channel("partner_api")->debug("/catalog/products/get_by_sku 1 2", [
            "queries" => ["username" => "someone"],
            "trace" => [["app/Services/CatalogClient", 427, "record"]],
        ]);

        [$line] = $this->queuedLines();

        $this->assertMatchesRegularExpression("/^[0-9a-f-]{36}$/", $line["event_id"]);
        $this->assertMatchesRegularExpression("/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}\\.\\d{3}[+-]\\d{2}:\\d{2}$/", $line["ts"]);
        $this->assertSame("debug", $line["level"]);
        $this->assertSame("partner_api", $line["props"]["channel"]);
        $this->assertSame("testing", $line["props"]["app_env"]);
        $this->assertSame("/catalog/products/get_by_sku", $line["props"]["endpoint"]);
        $this->assertTrue($line["props"]["success"]);
        $this->assertSame(2, $line["props"]["attempts"]);

        // Dropped context keys such as queries never leave the server.
        $this->assertArrayNotHasKey("queries", $line["props"]);
        $this->assertArrayHasKey("trace", $line["props"]);
    }

    public function test_the_ship_command_sends_the_queue_and_empties_it(): void
    {
        $fakeTransport = new FakeTransport();
        $this->app->instance(Transport::class, $fakeTransport);
        $this->app->forgetInstance(Shipper::class);

        foreach (range(1, 120) as $lineNumber) {
            Log::channel("partner_api")->info("/orders/list 1 1");
        }

        $this->artisan("simpledatalogs:ship", ["--once" => true])
            ->expectsOutputToContain("shipped 120 lines")
            ->assertSuccessful();

        $this->assertSame([50, 50, 20], $fakeTransport->linesPerBody());
        $this->assertSame([], $this->queuedLines());
        $this->assertSame([], glob($this->outboxPath . "/sending/*.ndjson"));
    }

    public function test_logging_never_throws_when_the_outbox_cannot_be_written(): void
    {
        // A file where the outbox folder should be makes every append fail.
        file_put_contents($this->outboxPath, "not a folder");

        Log::channel("partner_api")->error("/catalog/products 0 3");

        $this->assertTrue(true);
        unlink($this->outboxPath);
    }
}
