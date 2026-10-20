<?php

namespace SimpleDataLogs\Laravel\Tests\Telemetry;

use SimpleDataLogs\Laravel\Transport;
use SimpleDataLogs\Laravel\Jobs\ShipOutboxJob;
use SimpleDataLogs\Laravel\Tests\FakeTransport;
use SimpleDataLogs\Laravel\Tests\Telemetry\Fixtures\FailingJob;
use SimpleDataLogs\Laravel\Tests\Telemetry\Fixtures\QueryingJob;

use Illuminate\Support\Facades\DB;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Jobs: one bundle per run with queue, connection and attempts, joined to the dispatching request's trace.
 */
class JobTelemetryTest extends TelemetryTestCase
{
    /**
     * A database queue on the in-memory connection, and no failed-job store.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app["config"]->set("queue.default", "database");
        $app["config"]->set("queue.connections.database", ["driver" => "database", "connection" => "testing", "table" => "jobs", "queue" => "default", "retry_after" => 90]);
        $app["config"]->set("queue.failed.driver", "null");
    }

    /**
     * Creates the jobs table.
     */
    protected function defineDatabaseMigrations(): void
    {
        Schema::create("jobs", function (Blueprint $table): void {
            $table->id();
            $table->string("queue")->index();
            $table->longText("payload");
            $table->unsignedTinyInteger("attempts");
            $table->unsignedInteger("reserved_at")->nullable();
            $table->unsignedInteger("available_at");
            $table->unsignedInteger("created_at");
        });
    }

    /**
     * The routes the tests call.
     */
    protected function defineRoutes($router): void
    {
        $router->get("/dispatch", function () {
            QueryingJob::dispatch();

            return "queued";
        });

        $router->get("/dispatch-sync", function () {
            QueryingJob::dispatchSync();

            return "ran";
        });

        $router->get("/dispatch-failing", function () {
            FailingJob::dispatch();

            return "queued";
        });
    }

    /**
     * Runs the next job on the database queue, as a worker would.
     */
    private function runNextQueuedJob(): void
    {
        $this->app->make("queue.worker")->runNextJob("database", "default", new WorkerOptions(sleep: 0, maxTries: 0));
    }

    public function test_a_queued_job_carries_the_trace_through_its_payload_into_the_worker(): void
    {
        $this->get("/dispatch")->assertOk();

        // The payload holds the request's trace under __sdl_trace.
        $request = $this->onlyBundle("request");
        $payload = json_decode((string) DB::table("jobs")->value("payload"), true);
        $this->assertSame(["trace_id" => $request["trace_id"], "span_id" => $request["span_id"]], $payload["__sdl_trace"]);

        $this->runNextQueuedJob();

        $job = $this->onlyBundle("job");
        $this->assertSame(QueryingJob::class, $job["name"]);
        $this->assertSame($request["trace_id"], $job["trace_id"]);
        $this->assertSame($request["span_id"], $job["parent_span_id"]);
        $this->assertSame("ok", $job["status"]);
        $this->assertSame(["queue" => "default", "connection" => "database", "attempts" => 1], array_intersect_key($job["props"], ["queue" => 1, "connection" => 1, "attempts" => 1]));
        $this->assertContains("select ? as order_id", array_column($this->children($job, "query"), "name"));
    }

    public function test_a_sync_job_inside_a_request_is_a_remote_child_of_it(): void
    {
        $this->get("/dispatch-sync")->assertOk();

        $request = $this->onlyBundle("request");
        $job = $this->onlyBundle("job");

        $this->assertSame($request["trace_id"], $job["trace_id"]);
        $this->assertSame($request["span_id"], $job["parent_span_id"]);
        $this->assertSame("sync", $job["props"]["connection"]);

        // The job's query belongs to the job, not to the request around it.
        $this->assertSame(["select ? as order_id"], array_column($this->children($job, "query"), "name"));
        $this->assertSame([], $this->children($request, "query"));
    }

    public function test_a_failed_job_is_an_error_with_its_exception_unhandled_and_sent_once(): void
    {
        $this->get("/dispatch-failing")->assertOk();
        $this->runNextQueuedJob();

        $job = $this->onlyBundle("job");

        $this->assertSame(FailingJob::class, $job["name"]);
        $this->assertSame("error", $job["status"]);
        $this->assertCount(1, $job["exceptions"]);
        $this->assertSame("Payment provider timed out", $job["exceptions"][0]["message"]);
        $this->assertFalse($job["exceptions"][0]["handled"]);

        // The worker reports the exception after the bundle is written; it is not sent again on its own.
        $this->assertSame([], $this->bundles("exception"));
    }

    public function test_a_job_dispatched_outside_any_entry_point_starts_its_own_trace(): void
    {
        QueryingJob::dispatch();

        $payload = json_decode((string) DB::table("jobs")->value("payload"), true);
        $this->assertArrayNotHasKey("__sdl_trace", $payload);

        $this->runNextQueuedJob();

        $job = $this->onlyBundle("job");
        $this->assertArrayNotHasKey("parent_span_id", $job);
        $this->assertMatchesRegularExpression("/^[0-9a-f]{32}$/", $job["trace_id"]);
    }

    public function test_the_packages_own_ship_job_is_never_recorded(): void
    {
        $this->app->instance(Transport::class, new FakeTransport());

        dispatch_sync(new ShipOutboxJob());

        $this->assertSame([], $this->bundles("job"));
    }
}
