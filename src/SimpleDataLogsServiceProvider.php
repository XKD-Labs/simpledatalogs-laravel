<?php

namespace SimpleDataLogs\Laravel;

use SimpleDataLogs\Laravel\Telemetry\Release;
use SimpleDataLogs\Laravel\Telemetry\Sampler;
use SimpleDataLogs\Laravel\Jobs\ShipOutboxJob;
use SimpleDataLogs\Laravel\Telemetry\Recorder;
use SimpleDataLogs\Laravel\Console\ShipCommand;
use SimpleDataLogs\Laravel\Telemetry\Normalizer;
use SimpleDataLogs\Laravel\Monolog\TraceProcessor;
use SimpleDataLogs\Laravel\Monolog\AddTraceProcessor;
use SimpleDataLogs\Laravel\Listeners\AuthEventSubscriber;
use SimpleDataLogs\Laravel\Telemetry\Listeners\JobListener;
use SimpleDataLogs\Laravel\Telemetry\Listeners\MailListener;
use SimpleDataLogs\Laravel\Telemetry\Listeners\CacheListener;
use SimpleDataLogs\Laravel\Telemetry\Listeners\QueryListener;
use SimpleDataLogs\Laravel\Telemetry\Listeners\CommandListener;
use SimpleDataLogs\Laravel\Telemetry\Listeners\RequestListener;
use SimpleDataLogs\Laravel\Telemetry\Listeners\ExceptionListener;
use SimpleDataLogs\Laravel\Telemetry\Listeners\HttpClientListener;
use SimpleDataLogs\Laravel\Telemetry\Listeners\NotificationListener;
use SimpleDataLogs\Laravel\Telemetry\Listeners\ScheduledTaskListener;

use Illuminate\Container\Container;
use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Http\Kernel as HttpKernel;

/**
 * Wires the forwarder, the event tracker and telemetry: config, the outbox, the transport, the shipper, delivery after each response, and the ship command.
 */
class SimpleDataLogsServiceProvider extends ServiceProvider
{
    // The telemetry listener for each config switch, in the order they are subscribed.
    private const SIGNAL_LISTENERS = [
        "requests" => RequestListener::class,
        "queries" => QueryListener::class,
        "http" => HttpClientListener::class,
        "cache" => CacheListener::class,
        "mail" => MailListener::class,
        "notifications" => NotificationListener::class,
        "jobs" => JobListener::class,
        "tasks" => ScheduledTaskListener::class,
        "commands" => CommandListener::class,
    ];

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
            recorder: $this->isTelemetryOn() ? $app->make(Recorder::class) : null,
            isBackgroundProcess: $this->isBackgroundProcess(),
        ));

        $this->app->singleton(Shipper::class, fn ($app): Shipper => new Shipper(
            outbox: $app->make(Outbox::class),
            transport: $app->make(Transport::class),
            batchLines: (int) config("simpledatalogs.batch_lines", 50),
            batchBytes: (int) config("simpledatalogs.batch_bytes", 262144),
        ));

        $this->registerTelemetry();
    }

    /**
     * Publishes the config, registers the ship command, the opt-in auth listener, telemetry and delivery after each response.
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

        // Telemetry must never stop the app from booting.
        try {
            $this->bootTelemetry();
        } catch (\Throwable $error) {
            error_log("[simpledatalogs] telemetry was not started: " . $error->getMessage());
        }

        // Without cron: the request's bundle is written, then shipped once the response is out (or a job is queued), as configured.
        $this->app->terminating(function (): void {
            $this->flushTelemetry();
            $this->deliverAfterResponse();
        });
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
     * True when telemetry is switched on and a key is set to send it with.
     */
    private function isTelemetryOn(): bool
    {
        return (bool) config("simpledatalogs.telemetry.enabled", true) && trim((string) config("simpledatalogs.key")) !== "";
    }

    /**
     * True when one signal's switch is on (and telemetry as a whole).
     */
    private function isSignalOn(string $signalName): bool
    {
        return $this->isTelemetryOn() && (bool) config("simpledatalogs.telemetry.{$signalName}", true);
    }

    /**
     * Registers the recorder, its parts and the listeners as singletons; nothing is built until telemetry boots.
     */
    private function registerTelemetry(): void
    {
        $this->app->singleton(Sampler::class, fn (): Sampler => new Sampler(
            slowRequestMs: (int) config("simpledatalogs.telemetry.slow_request_ms", 500),
            slowJobMs: (int) config("simpledatalogs.telemetry.slow_job_ms", 5000),
            detailEvery: (int) config("simpledatalogs.telemetry.detail_every", 10),
            sampleRate: (float) config("simpledatalogs.telemetry.sample_rate", 1.0),
        ));

        $this->app->singleton(Normalizer::class, fn ($app): Normalizer => new Normalizer(
            basePath: $app->basePath(),
            appEnvironment: (string) config("app.env"),
            release: Release::resolve(config("simpledatalogs.release"), $app->basePath(), config("simpledatalogs.app_version")),
            dropPropKeys: config("simpledatalogs.drop_context_keys", []),
        ));

        $this->app->singleton(Recorder::class, fn ($app): Recorder => new Recorder(
            outbox: $app->make(Outbox::class),
            sampler: $app->make(Sampler::class),
            normalizer: $app->make(Normalizer::class),
            shouldRecordCacheKeys: (bool) config("simpledatalogs.telemetry.cache_keys", false),
            fingerprintResolver: $this->fingerprintResolver(),
        ));

        $this->app->singleton(TraceProcessor::class);

        // The request listener doubles as middleware, so it must be the same instance the events reach.
        $this->app->singleton(RequestListener::class, fn ($app): RequestListener => new RequestListener(
            recorder: $app->make(Recorder::class),
            userIdResolver: fn (): ?string => Container::getInstance()->make(EventTracker::class)->userId(),
        ));

        $this->app->singleton(JobListener::class, fn ($app): JobListener => new JobListener(
            recorder: $app->make(Recorder::class),
            ignoredJobNames: config("simpledatalogs.telemetry.ignore_jobs", []),
        ));

        $this->app->singleton(CommandListener::class, fn ($app): CommandListener => new CommandListener(
            recorder: $app->make(Recorder::class),
            ignoredCommandNames: config("simpledatalogs.telemetry.ignore_commands", []),
        ));

        foreach ([QueryListener::class, HttpClientListener::class, CacheListener::class, MailListener::class, NotificationListener::class, ScheduledTaskListener::class, ExceptionListener::class] as $listenerClass) {
            $this->app->singleton($listenerClass);
        }
    }

    /**
     * Subscribes each switched-on signal, the job payload hook, the exception hook, the request boundaries and the log processor.
     */
    private function bootTelemetry(): void
    {
        if (! $this->isTelemetryOn()) {
            return;
        }

        // Resolved here, in the base app, so Octane's per-request sandboxes share one recorder.
        $recorder = $this->app->make(Recorder::class);
        $events = $this->app["events"];

        foreach (self::SIGNAL_LISTENERS as $signalName => $listenerClass) {
            if ($this->isSignalOn($signalName)) {
                $this->app->make($listenerClass)->subscribe($events);
            }
        }

        if ($this->isSignalOn("requests")) {
            $this->bootRequestBoundaries($recorder);
        }

        if ($this->isSignalOn("jobs")) {
            $this->addJobPayloadHook();
        }

        if ($this->isSignalOn("exceptions")) {
            $this->app->make(ExceptionListener::class)->register($this->app);
        }

        if ($this->isSignalOn("logs")) {
            $this->addTraceProcessorToChannels();
        }
    }

    /**
     * Opens the request as early as boot (outside Octane), and makes the request listener the first global middleware.
     */
    private function bootRequestBoundaries(Recorder $recorder): void
    {
        // A plain PHP-FPM request: start at LARAVEL_START, so the duration covers the framework's own boot.
        if (! $this->app->runningInConsole() && ! $this->isOctane()) {
            $requestStartedAt = defined("LARAVEL_START") ? (float) LARAVEL_START : ($_SERVER["REQUEST_TIME_FLOAT"] ?? null);
            $recorder->beginRequest(is_numeric($requestStartedAt) ? (float) $requestStartedAt : null);
        }

        $addMiddleware = function (mixed $httpKernel): void {
            if (is_object($httpKernel) && method_exists($httpKernel, "prependMiddleware")) {
                $httpKernel->prependMiddleware(RequestListener::class);
            }
        };

        $this->app->afterResolving(HttpKernel::class, $addMiddleware);

        if ($this->app->resolved(HttpKernel::class)) {
            $addMiddleware($this->app->make(HttpKernel::class));
        }
    }

    /**
     * Puts `__sdl_trace` in every queued job's payload while an entry point runs, so the worker's job joins its trace.
     */
    private function addJobPayloadHook(): void
    {
        if (! class_exists(\Illuminate\Queue\Queue::class)) {
            return;
        }

        // Static on the queue, so the recorder is looked up at dispatch time, never captured.
        \Illuminate\Queue\Queue::createPayloadUsing(static function (): array {
            try {
                $container = Container::getInstance();

                return $container->resolved(Recorder::class) ? $container->make(Recorder::class)->payloadTrace() : [];
            } catch (\Throwable) {
                return [];
            }
        });
    }

    /**
     * Adds the trace processor tap to every configured log channel, so records carry trace_id and span_id.
     */
    private function addTraceProcessorToChannels(): void
    {
        foreach (array_keys((array) config("logging.channels", [])) as $channelName) {
            $channelTaps = (array) config("logging.channels.{$channelName}.tap", []);

            if (! in_array(AddTraceProcessor::class, $channelTaps, true)) {
                config(["logging.channels.{$channelName}.tap" => [...$channelTaps, AddTraceProcessor::class]]);
            }
        }
    }

    /**
     * Writes the bundles of what ended, before delivery ships them.
     */
    private function flushTelemetry(): void
    {
        try {
            if ($this->app->resolved(Recorder::class)) {
                $this->app->make(Recorder::class)->flush();
            }
        } catch (\Throwable $error) {
            error_log("[simpledatalogs] telemetry was not written at termination: " . $error->getMessage());
        }
    }

    /**
     * True in a queue worker, a command or the scheduler, where nobody is at the screen.
     */
    private function isBackgroundProcess(): bool
    {
        // Octane serves requests from a console process, so it never counts.
        return $this->app->runningInConsole() && ! $this->app->runningUnitTests() && ! $this->isOctane();
    }

    /**
     * True inside an Octane worker, where Octane's own events mark each request.
     */
    private function isOctane(): bool
    {
        return ! empty($_SERVER["LARAVEL_OCTANE"]) || ! empty($_ENV["LARAVEL_OCTANE"]);
    }

    /**
     * The configured fingerprint callback as a closure, or null for the default grouping.
     *
     * @return \Closure(\Throwable): mixed|null
     */
    private function fingerprintResolver(): ?\Closure
    {
        $fingerprint = config("simpledatalogs.fingerprint");

        // An invokable class name is resolved from the container.
        if (is_string($fingerprint) && class_exists($fingerprint)) {
            $fingerprint = $this->app->make($fingerprint);
        }

        return is_callable($fingerprint) ? \Closure::fromCallable($fingerprint) : null;
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
