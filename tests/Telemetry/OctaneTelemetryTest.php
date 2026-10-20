<?php

namespace SimpleDataLogs\Laravel\Tests\Telemetry;

use Laravel\Octane\Events\RequestHandled;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . "/Fixtures/OctaneEvents.php";

/**
 * Octane: one worker serves many requests; its request events mark where each bundle starts and ends.
 */
class OctaneTelemetryTest extends TelemetryTestCase
{
    /**
     * Plays one Octane request through the worker events, running some queries in between.
     */
    private function serveOctaneRequest(string $uri, int $queryCount, array $headers = [], bool $shouldTerminate = true): void
    {
        $request = Request::create($uri, "GET");

        foreach ($headers as $headerName => $headerValue) {
            $request->headers->set($headerName, $headerValue);
        }

        $route = new Route(["GET"], "reports/{report}", fn () => null);
        $request->setRouteResolver(fn () => $route);
        $response = new Response("ok", 200);

        $this->app["events"]->dispatch(new RequestReceived($this->app, $this->app, $request));

        foreach (range(1, $queryCount) as $queryNumber) {
            DB::select("select ? as n", [$queryNumber]);
        }

        if (! $shouldTerminate) {
            return;
        }

        $this->app["events"]->dispatch(new RequestHandled($this->app, $request, $response));
        $this->app["events"]->dispatch(new RequestTerminated($this->app, $this->app, $request, $response));
    }

    public function test_each_octane_request_is_its_own_bundle_with_its_own_children_and_trace(): void
    {
        $this->serveOctaneRequest("/reports/1", 2, ["traceparent" => "00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01"]);
        $this->serveOctaneRequest("/reports/2", 5);

        $bundles = $this->bundles("request");

        $this->assertCount(2, $bundles);
        $this->assertSame(["GET /reports/{report}", "GET /reports/{report}"], array_column($bundles, "name"));
        $this->assertSame([2, 5], [$bundles[0]["spans"][0]["count"], $bundles[1]["spans"][0]["count"]]);
        $this->assertSame("4bf92f3577b34da6a3ce929d0e0e4736", $bundles[0]["trace_id"]);
        $this->assertNotSame($bundles[0]["trace_id"], $bundles[1]["trace_id"]);
        $this->assertArrayNotHasKey("parent_span_id", $bundles[1]);
    }

    public function test_a_request_that_never_ended_is_written_when_the_next_one_arrives(): void
    {
        $this->serveOctaneRequest("/reports/1", 1, shouldTerminate: false);
        $this->assertSame([], $this->bundles("request"));

        $this->serveOctaneRequest("/reports/2", 3);

        $bundles = $this->bundles("request");
        $this->assertCount(2, $bundles);
        $this->assertSame([1, 3], [$bundles[0]["spans"][0]["count"], $bundles[1]["spans"][0]["count"]]);
    }
}
