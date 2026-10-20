<?php

namespace SimpleDataLogs\Laravel\Telemetry\Listeners;

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;

/**
 * Outgoing HTTP through Laravel's client: `METHOD host`, status and duration; the path and query are never sent.
 */
class HttpClientListener extends Listener
{
    // Start times waiting for their answer are dropped past this many (a pool that never answered).
    private const MAX_WAITING_CALLS = 100;

    // When each call in flight started, by its request object.
    /** @var array<int, float> */
    private array $startedAtByRequest = [];

    /**
     * Listens for calls leaving, answers arriving and connections failing.
     */
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(RequestSending::class, $this->handleRequestSending(...));
        $events->listen(ResponseReceived::class, $this->handleResponseReceived(...));
        $events->listen(ConnectionFailed::class, $this->handleConnectionFailed(...));
    }

    /**
     * Notes when a call leaves, only while an entry point is recorded.
     */
    public function handleRequestSending(RequestSending $event): void
    {
        if ($this->recorder->current() === null) {
            return;
        }

        if (count($this->startedAtByRequest) >= self::MAX_WAITING_CALLS) {
            $this->startedAtByRequest = [];
        }

        $this->startedAtByRequest[spl_object_id($event->request)] = microtime(true);
    }

    /**
     * Records an answered call with its status; Guzzle's own transfer time wins when it has one.
     */
    public function handleResponseReceived(ResponseReceived $event): void
    {
        $this->safely("an outgoing call", function () use ($event): void {
            $startedAt = $this->pullStartedAt($event->request);
            $durationMs = $this->transferMs($event->response) ?? ($startedAt === null ? 0.0 : (microtime(true) - $startedAt) * 1000);

            $this->recorder->recordHttp($event->request->method(), $this->host($event->request), $durationMs, $startedAt, $event->response->status());
        });
    }

    /**
     * Records a call that never got an answer as an error without a status.
     */
    public function handleConnectionFailed(ConnectionFailed $event): void
    {
        $this->safely("a failed outgoing call", function () use ($event): void {
            $startedAt = $this->pullStartedAt($event->request);
            $durationMs = $startedAt === null ? 0.0 : (microtime(true) - $startedAt) * 1000;

            $this->recorder->recordHttp($event->request->method(), $this->host($event->request), $durationMs, $startedAt, null);
        });
    }

    /**
     * Takes the noted start time of a call, if it was noted.
     */
    private function pullStartedAt(Request $request): ?float
    {
        $requestId = spl_object_id($request);
        $startedAt = $this->startedAtByRequest[$requestId] ?? null;
        unset($this->startedAtByRequest[$requestId]);

        return $startedAt;
    }

    /**
     * Guzzle's measured transfer time in milliseconds, when the handler reported one.
     */
    private function transferMs(Response $response): ?float
    {
        $totalSeconds = $response->handlerStats()["total_time"] ?? null;

        return is_numeric($totalSeconds) && $totalSeconds > 0 ? (float) $totalSeconds * 1000 : null;
    }

    /**
     * The host the call went to, without scheme, path or query.
     */
    private function host(Request $request): string
    {
        return (string) parse_url($request->url(), PHP_URL_HOST);
    }
}
