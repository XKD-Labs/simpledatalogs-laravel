<?php

namespace SimpleDataLogs\Laravel\Tests\Telemetry;

use SimpleDataLogs\Laravel\Facades\SimpleDataLogs;

use Illuminate\Support\Facades\DB;

/**
 * A request becomes one bundle at termination: route pattern, status, trace, release and its exceptions.
 */
class RequestTelemetryTest extends TelemetryTestCase
{
    /**
     * The routes the tests call.
     */
    protected function defineRoutes($router): void
    {
        $router->get("/orders/{order}", function (string $order) {
            DB::select("select ? as id", [$order]);

            return "order";
        })->name("orders.show");

        $router->get("/checkout", fn () => throw new \RuntimeException("Card declined for order 8812"));

        $router->get("/reported", function () {
            report(new \LogicException("Stock count drifted"));

            return "fine";
        });

        $router->get("/me", function () {
            SimpleDataLogs::setUserId(7);

            return "me";
        });

        $router->get("/pdf", fn () => SimpleDataLogs::span("render-pdf", fn () => "pdf-bytes", ["pages" => 3]));
    }

    public function test_a_request_sends_one_bundle_with_the_route_pattern_status_trace_and_release(): void
    {
        $this->get("/orders/zz-order-ref")->assertOk();

        $bundle = $this->onlyBundle("request");

        $this->assertSame("GET /orders/{order}", $bundle["name"]);
        $this->assertSame("orders.show", $bundle["route"]);
        $this->assertSame("GET", $bundle["method"]);
        $this->assertSame(200, $bundle["http_status"]);
        $this->assertSame("ok", $bundle["status"]);
        $this->assertSame("2026.10.3", $bundle["release"]);
        $this->assertMatchesRegularExpression("/^[0-9a-f]{32}$/", $bundle["trace_id"]);
        $this->assertMatchesRegularExpression("/^[0-9a-f]{16}$/", $bundle["span_id"]);
        $this->assertMatchesRegularExpression("/^[0-9a-f-]{36}$/", $bundle["event_id"]);
        $this->assertMatchesRegularExpression("/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}\\.\\d{3}Z$/", $bundle["ts"]);
        $this->assertIsFloat($bundle["duration_ms"]);
        $this->assertSame("testing", $bundle["props"]["app_env"]);
        $this->assertArrayHasKey("memory_mb", $bundle["props"]);
        $this->assertArrayNotHasKey("parent_span_id", $bundle);

        // The route value never leaves; the query is a child with the root's start as its zero.
        $this->assertStringNotContainsString("zz-order-ref", $this->outboxText());
        $this->assertCount(1, $this->children($bundle, "query"));
        $this->assertSame(["count" => 1], array_intersect_key($bundle["summary"]["queries"], ["count" => true]));
    }

    public function test_an_incoming_traceparent_joins_the_callers_trace(): void
    {
        $this->get("/orders/1", ["traceparent" => "00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01"])->assertOk();

        $bundle = $this->onlyBundle("request");

        $this->assertSame("4bf92f3577b34da6a3ce929d0e0e4736", $bundle["trace_id"]);
        $this->assertSame("00f067aa0ba902b7", $bundle["parent_span_id"]);
        $this->assertNotSame("00f067aa0ba902b7", $bundle["span_id"]);
    }

    public function test_a_malformed_traceparent_starts_a_new_trace(): void
    {
        $this->get("/orders/1", ["traceparent" => "00-00000000000000000000000000000000-00f067aa0ba902b7-01"])->assertOk();

        $bundle = $this->onlyBundle("request");

        $this->assertNotSame("00000000000000000000000000000000", $bundle["trace_id"]);
        $this->assertArrayNotHasKey("parent_span_id", $bundle);
    }

    public function test_an_uncaught_exception_makes_the_request_an_error_with_the_exception_unhandled(): void
    {
        $this->get("/checkout")->assertStatus(500);

        $bundle = $this->onlyBundle("request");

        $this->assertSame("error", $bundle["status"]);
        $this->assertSame(500, $bundle["http_status"]);
        $this->assertCount(1, $bundle["exceptions"]);

        $exception = $bundle["exceptions"][0];
        $this->assertSame(\RuntimeException::class, $exception["type"]);
        $this->assertSame("Card declined for order 8812", $exception["message"]);
        $this->assertFalse($exception["handled"]);
        $this->assertNull($exception["fingerprint"]);
        $this->assertNotEmpty($exception["frames"]);
        $this->assertSame(["file", "line", "function", "in_app"], array_keys($exception["frames"][0]));

        // The exception rides in the bundle, never on its own as well.
        $this->assertSame([], $this->bundles("exception"));
    }

    public function test_a_reported_exception_stays_handled_and_the_request_stays_ok(): void
    {
        $this->get("/reported")->assertOk();

        $bundle = $this->onlyBundle("request");

        $this->assertSame("ok", $bundle["status"]);
        $this->assertSame(\LogicException::class, $bundle["exceptions"][0]["type"]);
        $this->assertTrue($bundle["exceptions"][0]["handled"]);
    }

    public function test_the_users_id_comes_from_the_existing_resolver(): void
    {
        $this->get("/me")->assertOk();

        $this->assertSame("7", $this->onlyBundle("request")["user_id"]);
    }

    public function test_an_unmatched_url_keeps_a_readable_name_without_the_path(): void
    {
        $this->get("/no/such/page/42")->assertNotFound();

        $bundle = $this->onlyBundle("request");

        $this->assertSame("GET (unmatched)", $bundle["name"]);
        $this->assertSame(404, $bundle["http_status"]);
        $this->assertStringNotContainsString("no/such", $this->outboxText());
    }

    public function test_a_custom_span_is_a_child_and_returns_the_callbacks_value(): void
    {
        $this->get("/pdf")->assertOk()->assertSee("pdf-bytes");

        [$span] = $this->children($this->onlyBundle("request"), "span");

        $this->assertSame("render-pdf", $span["name"]);
        $this->assertSame(["pages" => 3], $span["props"]);
    }

    public function test_each_request_is_its_own_bundle_and_trace(): void
    {
        $this->get("/orders/1")->assertOk();
        $this->get("/orders/2")->assertOk();

        $bundles = $this->bundles("request");

        $this->assertCount(2, $bundles);
        $this->assertNotSame($bundles[0]["trace_id"], $bundles[1]["trace_id"]);
        $this->assertNotSame($bundles[0]["event_id"], $bundles[1]["event_id"]);
    }
}
