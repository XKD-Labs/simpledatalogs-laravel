<?php

namespace SimpleDataLogs\Laravel\Telemetry\Listeners;

use SimpleDataLogs\Laravel\Telemetry\Recorder;

use Illuminate\Contracts\Events\Dispatcher;

/**
 * The shape every telemetry listener shares: the recorder, a subscribe step, and handlers that never throw.
 */
abstract class Listener
{
    /**
     * Keeps the recorder every handler writes to.
     */
    public function __construct(protected Recorder $recorder)
    {
    }

    /**
     * Listens for this signal's framework events.
     */
    abstract public function subscribe(Dispatcher $events): void;

    /**
     * Runs a handler body; a failure goes to PHP's error log and the app carries on (fail open).
     */
    protected function safely(string $whatIsRecorded, \Closure $handlerBody): void
    {
        try {
            $handlerBody();
        } catch (\Throwable $error) {
            error_log("[simpledatalogs] {$whatIsRecorded} was not recorded: " . $error->getMessage());
        }
    }
}
