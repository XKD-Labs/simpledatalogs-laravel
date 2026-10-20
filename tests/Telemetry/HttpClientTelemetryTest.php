<?php

namespace SimpleDataLogs\Laravel\Tests\Telemetry;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;

/**
 * Outgoing HTTP: `METHOD host`, status and duration; paths and query strings never leave.
 */
class HttpClientTelemetryTest extends TelemetryTestCase
{
    /**
     * The routes the tests call, with every outgoing call faked.
     */
    protected function defineRoutes($router): void
    {
        $router->get("/pay", function () {
            Http::fake([
                "api.stripe.com/*" => Http::response(["id" => "ch_1"], 200),
                "flaky.example.test/*" => Http::response("", 503),
                "down.example.test/*" => Http::failedConnection(),
            ]);

            Http::post("https://api.stripe.com/v1/charges?customer=zz-customer-ref", ["amount" => 100]);
            Http::post("https://api.stripe.com/v1/charges?customer=zz-customer-ref", ["amount" => 200]);
            Http::get("https://flaky.example.test/status");

            try {
                Http::get("https://down.example.test/health");
            } catch (ConnectionException) {
                // The app handles it; the child still records the failure.
            }

            return "paid";
        });
    }

    public function test_outgoing_calls_are_children_named_by_method_and_host_with_their_status(): void
    {
        $this->get("/pay")->assertOk();

        $bundle = $this->onlyBundle("request");
        $callsByName = array_column($this->children($bundle, "http"), null, "name");

        $this->assertSame(["POST api.stripe.com", "GET flaky.example.test", "GET down.example.test"], array_keys($callsByName));

        $this->assertSame(2, $callsByName["POST api.stripe.com"]["count"]);
        $this->assertSame("ok", $callsByName["POST api.stripe.com"]["status"]);
        $this->assertSame(200, $callsByName["POST api.stripe.com"]["http_status"]);

        $this->assertSame("error", $callsByName["GET flaky.example.test"]["status"]);
        $this->assertSame(503, $callsByName["GET flaky.example.test"]["http_status"]);

        $this->assertSame("error", $callsByName["GET down.example.test"]["status"]);
        $this->assertArrayNotHasKey("http_status", $callsByName["GET down.example.test"]);

        $this->assertSame(4, $bundle["summary"]["http"]["count"]);

        // Paths and query strings never leave.
        $this->assertStringNotContainsString("/v1/charges", $this->outboxText());
        $this->assertStringNotContainsString("zz-customer-ref", $this->outboxText());
    }
}
