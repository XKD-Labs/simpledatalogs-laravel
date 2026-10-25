<?php

namespace SimpleDataLogs\Laravel\Tests;

use SimpleDataLogs\Laravel\Outbox;
use SimpleDataLogs\Laravel\Shipper;
use SimpleDataLogs\Laravel\Transport;
use SimpleDataLogs\Laravel\EventTracker;
use SimpleDataLogs\Laravel\Jobs\ShipOutboxJob;
use SimpleDataLogs\Laravel\Tests\Fixtures\User;
use SimpleDataLogs\Laravel\Tests\Fixtures\Order;
use SimpleDataLogs\Laravel\Facades\SimpleDataLogs;
use SimpleDataLogs\Laravel\SimpleDataLogsServiceProvider;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\Schema\Blueprint;

/**
 * Events, model changes and auth events reach the outbox as ingest API lines; delivery works without cron.
 */
class EventTrackingTest extends TestCase
{
    /**
     * Turns on the auth listener and gives the app an in-memory database.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app["config"]->set("simpledatalogs.track_auth_events", true);
        $app["config"]->set("database.default", "testing");
        $app["config"]->set("database.connections.testing", ["driver" => "sqlite", "database" => ":memory:", "prefix" => ""]);
        $app["config"]->set("auth.providers.users.model", User::class);
    }

    /**
     * Creates the orders and users tables.
     */
    protected function defineDatabaseMigrations(): void
    {
        Schema::create("orders", function (Blueprint $table): void {
            $table->id();
            $table->string("status");
            $table->string("note")->nullable();
            $table->string("card_token")->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create("users", function (Blueprint $table): void {
            $table->id();
            $table->string("email");
            $table->string("password");
            $table->rememberToken();
            $table->timestamps();
        });
    }

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

    public function test_the_facade_queues_an_event_with_id_time_scalar_props_and_user(): void
    {
        SimpleDataLogs::track(" signup ", ["plan" => "pro", "seats" => 3, "is_trial" => true, "nested" => ["a" => 1], "ratio" => INF], userId: 42);
        SimpleDataLogs::track("  ");

        $lines = $this->queuedLines();
        $this->assertCount(1, $lines);

        [$line] = $lines;
        $this->assertSame("signup", $line["event"]);
        $this->assertMatchesRegularExpression("/^[0-9a-f-]{36}$/", $line["event_id"]);
        $this->assertMatchesRegularExpression("/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}\\.\\d{3}[+-]\\d{2}:\\d{2}$/", $line["ts"]);
        $this->assertSame("42", $line["user_id"]);
        $this->assertSame(["plan" => "pro", "seats" => 3, "is_trial" => true, "app_env" => "testing"], $line["props"]);
    }

    public function test_events_from_a_worker_or_command_say_background_unless_the_caller_says_otherwise(): void
    {
        $tracker = new EventTracker($this->app->make(Outbox::class), "testing", isBackgroundProcess: true);

        $tracker->track("invoice_sent", ["plan" => "pro"]);
        $tracker->track("user_returned", ["app_state" => "active"]);

        $lines = $this->queuedLines();
        $this->assertSame(["app_state" => "background", "plan" => "pro", "app_env" => "testing"], $lines[0]["props"]);
        $this->assertSame("active", $lines[1]["props"]["app_state"]);
    }

    public function test_a_tracked_model_sends_created_updated_deleted_and_restored_with_names_not_values(): void
    {
        $order = Order::query()->create(["status" => "new", "note" => "leave at the door", "card_token" => "tok_secret"]);
        $order->update(["note" => "ring twice", "status" => "paid"]);
        $order->touch();
        $order->delete();
        $order->restore();

        $lines = $this->queuedLines();
        $this->assertSame(["order_created", "order_updated", "order_deleted", "order_restored"], array_column($lines, "event"));

        // Every event names the model and its id.
        foreach ($lines as $line) {
            $this->assertSame(Order::class, $line["props"]["model"]);
            $this->assertSame((string) $order->id, $line["props"]["model_id"]);
        }

        // An update lists changed names only; the whitelisted status sends its value, the hidden token never does.
        $updated = $lines[1]["props"];
        $this->assertSame("status,note", $updated["changed"]);
        $this->assertSame("paid", $updated["attributes.status"]);
        $this->assertArrayNotHasKey("attributes.card_token", $updated);
        $this->assertStringNotContainsString("ring twice", json_encode($lines));
        $this->assertStringNotContainsString("tok_secret", json_encode($lines));
    }

    public function test_the_auth_listener_sets_user_id_from_login_registration_and_clears_it_on_logout(): void
    {
        $user = User::query()->create(["email" => "someone@example.test", "password" => "hashed"]);

        Event::dispatch(new Registered($user));
        Auth::login($user);
        SimpleDataLogs::track("checkout_opened");
        Auth::logout();
        SimpleDataLogs::track("pricing_viewed");

        $lines = $this->queuedLines();
        $this->assertSame(["user_registered", "user_logged_in", "checkout_opened", "user_logged_out", "pricing_viewed"], array_column($lines, "event"));
        $this->assertSame([(string) $user->id, (string) $user->id, (string) $user->id, (string) $user->id], array_column(array_slice($lines, 0, 4), "user_id"));
        $this->assertArrayNotHasKey("user_id", $lines[4]);
    }

    public function test_the_signed_in_user_is_picked_up_without_a_login_event(): void
    {
        $user = User::query()->create(["email" => "someone@example.test", "password" => "hashed"]);

        Auth::setUser($user);
        SimpleDataLogs::track("report_exported");

        $this->assertSame((string) $user->id, $this->queuedLines()[0]["user_id"]);
    }

    public function test_terminate_delivery_ships_after_the_response(): void
    {
        $fakeTransport = new FakeTransport();
        $this->app["config"]->set("simpledatalogs.delivery", "terminate");
        $this->app->instance(Transport::class, $fakeTransport);
        $this->app->forgetInstance(Shipper::class);

        SimpleDataLogs::track("signup");
        $this->app->terminate();

        $this->assertCount(1, $fakeTransport->sentBodies);
        $this->assertStringContainsString("\"event\":\"signup\"", $fakeTransport->sentBodies[0]);
        $this->assertSame(0, $this->app->make(Outbox::class)->waitingBytes());
    }

    public function test_queue_delivery_queues_one_ship_job_and_schedule_delivery_does_nothing(): void
    {
        Queue::fake();
        $provider = $this->app->getProvider(SimpleDataLogsServiceProvider::class);

        // Nothing queued: no job.
        $this->app["config"]->set("simpledatalogs.delivery", "queue");
        $provider->deliverAfterResponse();
        Queue::assertNothingPushed();

        SimpleDataLogs::track("signup");
        $provider->deliverAfterResponse();
        Queue::assertPushed(ShipOutboxJob::class, 1);

        // The scheduler path leaves everything for simpledatalogs:ship.
        $this->app["config"]->set("simpledatalogs.delivery", "schedule");
        $provider->deliverAfterResponse();
        Queue::assertPushed(ShipOutboxJob::class, 1);
    }

    public function test_tracking_never_throws_when_the_outbox_is_unwritable(): void
    {
        $this->app->instance(EventTracker::class, new EventTracker(new Outbox("/proc/simpledatalogs-unwritable"), "testing"));

        SimpleDataLogs::clearResolvedInstances();
        SimpleDataLogs::track("signup");

        $this->assertTrue(true);
    }
}
