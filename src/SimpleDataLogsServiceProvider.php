<?php

namespace SimpleDataLogs\Laravel;

use SimpleDataLogs\Laravel\Jobs\ShipOutboxJob;
use SimpleDataLogs\Laravel\Console\ShipCommand;
use SimpleDataLogs\Laravel\Listeners\AuthEventSubscriber;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Bus\Dispatcher;

/**
 * Wires the forwarder and the event tracker: config, the outbox, the transport, the shipper, delivery after each response, and the ship command.
 */
class SimpleDataLogsServiceProvider extends ServiceProvider
{
    /**
     * Registers every piece as a singleton built from config('simpledatalogs').
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . "/../config/simpledatalogs.php", "simpledatalogs");

        $this->app->singleton(Outbox::class, fn (): Outbox => new Outbox(
            outboxPath: config("simpledatalogs.outbox_path"),
            maxOutboxBytes: (int) config("simpledatalogs.max_outbox_bytes", 536870912),
            maxFileBytes: (int) config("simpledatalogs.max_file_bytes", 67108864),
        ));

        $this->app->singleton(LineBuilder::class, fn (): LineBuilder => new LineBuilder(
            appEnvironment: (string) config("app.env"),
            messageFields: config("simpledatalogs.message_fields", []),
            dropContextKeys: config("simpledatalogs.drop_context_keys", []),
            contextAllowlist: config("simpledatalogs.context_allowlist"),
        ));

        $this->app->singleton(Transport::class, fn (): Transport => new HttpTransport(
            ingestUrl: (string) config("simpledatalogs.url"),
            ingestKey: (string) config("simpledatalogs.key"),
        ));

        // Scoped, so a long-lived worker (Octane, queues) starts each request without the last one's user.
        $this->app->scoped(EventTracker::class, fn ($app): EventTracker => new EventTracker(
            outbox: $app->make(Outbox::class),
            appEnvironment: (string) config("app.env"),
            currentUserIdResolver: config("simpledatalogs.track_auth_events") ? $this->signedInUserIdResolver() : null,
        ));

        $this->app->singleton(Shipper::class, fn ($app): Shipper => new Shipper(
            outbox: $app->make(Outbox::class),
            transport: $app->make(Transport::class),
            batchLines: (int) config("simpledatalogs.batch_lines", 50),
            batchBytes: (int) config("simpledatalogs.batch_bytes", 262144),
        ));
    }

    /**
     * Publishes the config, registers the ship command, the opt-in auth listener and delivery after each response.
     */
    public function boot(): void
    {
        $this->publishes([__DIR__ . "/../config/simpledatalogs.php" => config_path("simpledatalogs.php")], "simpledatalogs-config");

        if ($this->app->runningInConsole()) {
            $this->commands([ShipCommand::class]);
        }

        // Sign-ins, sign-outs and sign-ups, only when asked for.
        if (config("simpledatalogs.track_auth_events")) {
            $this->app["events"]->subscribe(AuthEventSubscriber::class);
        }

        // Without cron: ship once the response is out (or queue a job), as configured.
        $this->app->terminating(fn () => $this->deliverAfterResponse());
    }

    /**
     * Ships what this request queued, or queues a ship job; `schedule` leaves it to the scheduled command.
     */
    public function deliverAfterResponse(): void
    {
        $delivery = (string) config("simpledatalogs.delivery", "terminate");

        if ($delivery === "schedule") {
            return;
        }

        // Delivery must never break the app's shutdown.
        try {
            $outbox = $this->app->make(Outbox::class);

            if ($outbox->waitingBytes() === 0) {
                return;
            }

            // A queue worker ships; one job waits at a time.
            if ($delivery === "queue") {
                $this->app->make(Dispatcher::class)->dispatch(new ShipOutboxJob());

                return;
            }

            // The response is already sent; ship for a few seconds, and another process holding the lock means it is shipping already.
            $this->app->make(Shipper::class)->ship(time() + (int) config("simpledatalogs.terminate_ship_seconds", 3));
        } catch (\Throwable $error) {
            error_log("[simpledatalogs] delivery after the response failed: " . $error->getMessage());
        }
    }

    /**
     * The signed-in user's id, only when the guard already resolved one, so tracking never adds a query.
     *
     * @return \Closure(): (int|string|null)
     */
    private function signedInUserIdResolver(): \Closure
    {
        return function (): int|string|null {
            $authManager = $this->app["auth"];

            return $authManager->guard()->hasUser() ? $authManager->guard()->id() : null;
        };
    }
}
