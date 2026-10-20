<?php

namespace SimpleDataLogs\Laravel\Telemetry\Listeners;

use SimpleDataLogs\Laravel\Telemetry\Recorder;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Http\Events\RequestHandled;

/**
 * Requests: method, route pattern and name, status, duration, peak memory and user id; Octane's request events mark the boundaries in a long-lived worker.
 * It is also the first global middleware, which opens the request and reads an incoming `traceparent`.
 */
class RequestListener extends Listener
{
    // Octane's events, listened for by name so the package never needs Octane installed.
    private const OCTANE_REQUEST_RECEIVED = "Laravel\\Octane\\Events\\RequestReceived";
    private const OCTANE_REQUEST_HANDLED = "Laravel\\Octane\\Events\\RequestHandled";
    private const OCTANE_REQUEST_TERMINATED = "Laravel\\Octane\\Events\\RequestTerminated";

    // A request start time older than this (seconds) is not trusted; the middleware's own clock is used instead.
    private const MAX_REQUEST_AGE_SECONDS = 300;

    /**
     * Keeps the recorder and how to find the request's user id (the event tracker's resolver).
     *
     * @param \Closure(): (string|null)|null $userIdResolver
     */
    public function __construct(Recorder $recorder, private ?\Closure $userIdResolver = null)
    {
        parent::__construct($recorder);
    }

    /**
     * Listens for the request's end, and for Octane's request boundaries.
     */
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(RequestHandled::class, fn (RequestHandled $event) => $this->endRequest($event->request, $event->response));
        $events->listen(self::OCTANE_REQUEST_RECEIVED, $this->handleOctaneRequestReceived(...));
        $events->listen(self::OCTANE_REQUEST_HANDLED, fn (object $event) => $this->endRequest($event->request ?? null, $event->response ?? null));
        $events->listen(self::OCTANE_REQUEST_TERMINATED, fn () => $this->recorder->flush());
    }

    /**
     * The middleware step: opens the request (or joins the one opened at boot) with the caller's trace.
     */
    public function handle(Request $request, \Closure $next): mixed
    {
        $this->recorder->beginRequest($this->requestStartedAt($request), $request->headers->get("traceparent"));

        return $next($request);
    }

    /**
     * A new Octane request: whatever the last one left is written, then a fresh request opens.
     */
    public function handleOctaneRequestReceived(object $event): void
    {
        $this->safely("an Octane request", function () use ($event): void {
            $this->recorder->flush();

            $request = $event->request ?? null;
            $this->recorder->beginRequest(microtime(true), $request instanceof Request ? $request->headers->get("traceparent") : null);
        });
    }

    /**
     * Fills the request's root from the route and response, then parks it until termination.
     */
    public function endRequest(mixed $request, mixed $response): void
    {
        $this->safely("a request", function () use ($request, $response): void {
            $entry = $this->recorder->openEntry("request");

            if ($entry === null || ! $request instanceof Request || ! is_object($response) || ! method_exists($response, "getStatusCode")) {
                return;
            }

            // The route pattern keeps parameters as {name}, never their values; no route keeps the name readable.
            $route = $request->route();
            $method = strtoupper($request->getMethod());
            $routePattern = $route instanceof Route ? "/" . ltrim($route->uri(), "/") : "(unmatched)";
            $httpStatus = (int) $response->getStatusCode();

            $entry->name = $method . " " . $routePattern;
            $entry->fields = ["http_status" => $httpStatus, "method" => $method];

            if ($route instanceof Route && is_string($route->getName()) && $route->getName() !== "") {
                $entry->fields["route"] = $route->getName();
            }

            $userId = $this->userIdResolver === null ? null : ($this->userIdResolver)();

            if ($userId !== null && $userId !== "") {
                $entry->fields["user_id"] = (string) $userId;
            }

            $entry->props["memory_mb"] = round(memory_get_peak_usage(true) / 1048576, 1);
            $entry->props["php"] = PHP_VERSION;

            // The framework puts the rendered exception on the response; that one was not handled by the app.
            $renderedException = property_exists($response, "exception") ? $response->exception : null;
            $this->recorder->markUnhandled($entry, $renderedException, false);

            if ($httpStatus >= 500) {
                $entry->status = "error";
            }

            $this->recorder->park($entry);
        });
    }

    /**
     * When the request arrived, from the server's clock; recent and not in the future, else now.
     */
    private function requestStartedAt(Request $request): float
    {
        $now = microtime(true);
        $requestTime = $request->server("REQUEST_TIME_FLOAT");

        if (! is_numeric($requestTime) || (float) $requestTime > $now || $now - (float) $requestTime > self::MAX_REQUEST_AGE_SECONDS) {
            return $now;
        }

        return (float) $requestTime;
    }
}
