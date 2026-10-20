<?php

namespace SimpleDataLogs\Laravel\Tests\Telemetry;

use SimpleDataLogs\Laravel\Telemetry\Recorder;

/**
 * Telemetry stays cheap: recording a request with 20 queries and writing its bundle costs well under half a millisecond.
 */
class CostTest extends TelemetryTestCase
{
    public function test_a_request_with_twenty_queries_costs_under_half_a_millisecond(): void
    {
        $recorder = $this->app->make(Recorder::class);
        $batchMedians = [];

        // Five batches of 100 requests; the median batch is compared, so one noisy batch can't fail the test.
        foreach (range(1, 5) as $batchNumber) {
            $startedAt = hrtime(true);

            foreach (range(1, 100) as $requestNumber) {
                $entry = $recorder->beginRequest(microtime(true));

                foreach (range(1, 20) as $queryNumber) {
                    $recorder->recordQuery("select * from order_lines_" . ($queryNumber % 5) . " where order_id = ?", 0.4, "mysql");
                }

                $entry->name = "GET /orders/{order}";
                $entry->fields = ["http_status" => 200, "method" => "GET"];
                $recorder->park($entry);
                $recorder->flush();
            }

            $batchMedians[] = (hrtime(true) - $startedAt) / 1_000_000 / 100;
        }

        sort($batchMedians);
        $millisecondsPerRequest = $batchMedians[2];

        // Every bundle was written, in detail (detail_every is 1 here: the worst case).
        $this->assertCount(500, $this->bundles("request"));
        $this->assertCount(5, $this->bundles("request")[0]["spans"]);
        $this->assertLessThan(0.5, $millisecondsPerRequest, "one request cost {$millisecondsPerRequest} ms");

        // The figure, for whoever reads the test output.
        fwrite(STDERR, sprintf("[cost] %.3f ms per request with 20 queries\n", $millisecondsPerRequest));
    }
}
