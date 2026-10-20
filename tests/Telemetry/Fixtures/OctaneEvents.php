<?php

// Stand-ins for Octane's request events with the same public properties, so the package is tested without Octane installed.

namespace Laravel\Octane\Events;

/**
 * Octane dispatches this when a worker receives a request.
 */
class RequestReceived
{
    /**
     * Keeps the app, the sandbox and the request.
     */
    public function __construct(public mixed $app, public mixed $sandbox, public mixed $request)
    {
    }
}

/**
 * Octane dispatches this once the response is built.
 */
class RequestHandled
{
    /**
     * Keeps the sandbox, the request and the response.
     */
    public function __construct(public mixed $sandbox, public mixed $request, public mixed $response)
    {
    }
}

/**
 * Octane dispatches this after the response is sent.
 */
class RequestTerminated
{
    /**
     * Keeps the app, the sandbox, the request and the response.
     */
    public function __construct(public mixed $app, public mixed $sandbox, public mixed $request, public mixed $response)
    {
    }
}
