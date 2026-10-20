<?php

namespace SimpleDataLogs\Laravel\Telemetry\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;

/**
 * Exceptions the app reports: a `reportable()` callback on the exception handler, so `report()` and uncaught errors both arrive.
 * Failed jobs and tasks add theirs through their own listeners.
 */
class ExceptionListener extends Listener
{
    // Handlers that already carry the callback, so a re-resolved handler never gets two.
    /** @var \WeakMap<object, true>|null */
    private ?\WeakMap $registeredHandlers = null;

    /**
     * Exceptions arrive through the handler, not through events.
     */
    public function subscribe(Dispatcher $events): void
    {
    }

    /**
     * Adds the callback to the exception handler now if it exists, else as soon as it is resolved.
     */
    public function register(Application $app): void
    {
        $this->registeredHandlers ??= new \WeakMap();

        $app->afterResolving(ExceptionHandler::class, fn (mixed $exceptionHandler) => $this->addCallback($exceptionHandler));

        if ($app->resolved(ExceptionHandler::class)) {
            $this->addCallback($app->make(ExceptionHandler::class));
        }
    }

    /**
     * Records a reported exception as handled; a 5xx, a failed job or an uncaught console error flips it later.
     */
    public function handleReportedException(\Throwable $exception): void
    {
        $this->recorder->recordException($exception, true);
    }

    /**
     * Registers the reportable callback on a handler that supports it (the framework's default handler does).
     */
    private function addCallback(mixed $exceptionHandler): void
    {
        $this->safely("the exception hook", function () use ($exceptionHandler): void {
            if (! is_object($exceptionHandler) || ! method_exists($exceptionHandler, "reportable") || isset($this->registeredHandlers[$exceptionHandler])) {
                return;
            }

            // The callback returns nothing, so the app's own reporting carries on after it.
            $exceptionHandler->reportable(function (\Throwable $exception): void {
                $this->handleReportedException($exception);
            });

            $this->registeredHandlers[$exceptionHandler] = true;
        });
    }
}
