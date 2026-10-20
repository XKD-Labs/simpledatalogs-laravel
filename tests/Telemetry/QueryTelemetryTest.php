<?php

namespace SimpleDataLogs\Laravel\Tests\Telemetry;

use SimpleDataLogs\Laravel\Telemetry\Recorder;
use SimpleDataLogs\Laravel\Telemetry\Normalizer;

use Illuminate\Support\Facades\DB;

/**
 * Queries: SQL with `?` placeholders, identical SQL folded into one child with a count, bindings and literals never sent.
 */
class QueryTelemetryTest extends TelemetryTestCase
{
    /**
     * The routes the tests call.
     */
    protected function defineRoutes($router): void
    {
        $router->get("/order-lines", function () {
            DB::select("select ? as order_id", [1]);

            foreach (range(1, 37) as $lineNumber) {
                DB::select("select ? as line_id", [$lineNumber]);
            }

            return "lines";
        });

        $router->get("/lookup", function () {
            DB::select("select ? as email", ["zz-secret-buyer@example.test"]);
            DB::select("select 'zz-literal-buyer@example.test' as email, 42 as answer");

            return "lookup";
        });
    }

    public function test_identical_sql_collapses_into_one_child_with_count_summed_duration_and_slowest_run(): void
    {
        $this->get("/order-lines")->assertOk();

        $bundle = $this->onlyBundle("request");
        $queryChildren = $this->children($bundle, "query");

        $this->assertSame(["select ? as order_id", "select ? as line_id"], array_column($queryChildren, "name"));

        $lineQuery = $queryChildren[1];
        $this->assertSame(37, $lineQuery["count"]);
        $this->assertGreaterThanOrEqual($lineQuery["max_ms"], $lineQuery["duration_ms"]);
        $this->assertSame("testing", $lineQuery["props"]["connection"]);
        $this->assertMatchesRegularExpression("/^[0-9a-f]{16}$/", $lineQuery["span_id"]);
        $this->assertGreaterThanOrEqual(0, $lineQuery["start_ms"]);

        // The summary keeps the exact totals.
        $this->assertSame(38, $bundle["summary"]["queries"]["count"]);
        $this->assertArrayNotHasKey("max_ms", $queryChildren[0]);
    }

    public function test_bindings_and_literals_never_leave_the_app(): void
    {
        $this->get("/lookup")->assertOk();

        $queryNames = array_column($this->children($this->onlyBundle("request"), "query"), "name");

        $this->assertSame(["select ? as email", "select ? as email, ? as answer"], $queryNames);
        $this->assertStringNotContainsString("zz-secret-buyer", $this->outboxText());
        $this->assertStringNotContainsString("zz-literal-buyer", $this->outboxText());
    }

    public function test_queries_outside_any_entry_point_are_not_recorded(): void
    {
        DB::select("select 1");

        $this->assertSame([], $this->outboxLines());
    }

    public function test_a_loop_of_distinct_queries_stays_bounded_in_memory_and_on_the_wire(): void
    {
        $recorder = $this->app->make(Recorder::class);
        $entry = $recorder->beginRequest();

        foreach (range(1, 700) as $tableNumber) {
            $recorder->recordQuery("select * from report_part_{$tableNumber}", 0.1, "testing");
        }

        $this->assertLessThanOrEqual(501, count($entry->spans));

        $recorder->park($entry);
        $recorder->flush();

        $bundle = $this->onlyBundle("request");
        $this->assertCount(Normalizer::MAX_CHILDREN, $bundle["spans"]);
        $this->assertSame(700, array_sum(array_column($bundle["spans"], "count")));
        $this->assertSame(700, $bundle["summary"]["queries"]["count"]);
        $this->assertSame([Normalizer::OTHER_NAME], array_values(array_unique(array_filter(array_column($bundle["spans"], "name"), fn (string $name): bool => $name === Normalizer::OTHER_NAME))));
    }
}
