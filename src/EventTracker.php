<?php

namespace SimpleDataLogs\Laravel;

use SimpleDataLogs\Laravel\Telemetry\Recorder;

use Illuminate\Support\Str;

/**
 * Records named events (`SimpleDataLogs::track("signup", ["plan" => "pro"])`) into the same disk outbox as log lines.
 */
class EventTracker
{
    // The ingest API allows 100 props; app_env takes one, the rest is the app's.
    private const MAX_PROPS = 99;

    // The user id set by the auth listener or the app, for every later event of this request.
    private ?string $userId = null;

    /**
     * Keeps the outbox, the app environment, an optional resolver for the signed-in user's id and the telemetry recorder.
     *
     * @param \Closure(): (int|string|null)|null $currentUserIdResolver
     */
    public function __construct(
        private Outbox $outbox,
        private string $appEnvironment,
        private ?\Closure $currentUserIdResolver = null,
        private ?Recorder $recorder = null,
    ) {
    }

    /**
     * Runs a callback as a custom span inside the current request, job, task or command, and returns its result.
     * With telemetry off it only runs the callback; the callback's own exceptions still reach the app.
     *
     * @template TResult
     * @param callable(): TResult $callback
     * @param array<string, mixed> $props
     * @return TResult
     */
    public function span(string $spanName, callable $callback, array $props = []): mixed
    {
        if ($this->recorder === null) {
            return $callback();
        }

        return $this->recorder->span($spanName, $callback, $props);
    }

    /**
     * Queues one event with scalar props; it never throws into the app (fail open).
     *
     * @param array<string, mixed> $props
     */
    public function track(string $eventName, array $props = [], int|string|null $userId = null): void
    {
        try {
            // An empty name can't become an event.
            $trimmedEventName = trim($eventName);

            if ($trimmedEventName === "") {
                error_log("[simpledatalogs] track() needs a non-empty event name; the event was skipped");

                return;
            }

            // The id and time are set now, so a resend counts once.
            $line = [
                "event" => $trimmedEventName,
                "event_id" => (string) Str::uuid(),
                "ts" => (new \DateTimeImmutable())->format("Y-m-d\\TH:i:s.vP"),
            ];

            // An explicit user id wins over the request's.
            $resolvedUserId = $this->cleanIdentity($userId) ?? $this->userId();

            if ($resolvedUserId !== null) {
                $line["user_id"] = $resolvedUserId;
            }

            $line["props"] = array_merge($this->scalarProps($props), ["app_env" => $this->appEnvironment]);

            $this->outbox->append(json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        } catch (\Throwable $error) {
            error_log("[simpledatalogs] an event was not queued: " . $error->getMessage());
        }
    }

    /**
     * Sets the user id on every later event of this request, or clears it with null.
     */
    public function setUserId(int|string|null $userId): void
    {
        $this->userId = $this->cleanIdentity($userId);
    }

    /**
     * The user id later events carry: the one set, else the signed-in user's when the auth listener is on.
     */
    public function userId(): ?string
    {
        if ($this->userId !== null || $this->currentUserIdResolver === null) {
            return $this->userId;
        }

        // Resolving the user must never break tracking.
        try {
            return $this->cleanIdentity(($this->currentUserIdResolver)());
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Keeps string, finite number and boolean props (backed enums as their value), up to the ingest API limit.
     *
     * @param array<string, mixed> $props
     * @return array<string, string|int|float|bool>
     */
    private function scalarProps(array $props): array
    {
        $keptProps = [];

        foreach ($props as $propName => $propValue) {
            // Backed enums are common in models; their value is what a chart shows.
            if ($propValue instanceof \BackedEnum) {
                $propValue = $propValue->value;
            }

            $isKept = is_string($propValue) || is_int($propValue) || is_bool($propValue) || (is_float($propValue) && is_finite($propValue));

            if ($isKept && count($keptProps) < self::MAX_PROPS) {
                $keptProps[(string) $propName] = $propValue;
            }
        }

        return $keptProps;
    }

    /**
     * A trimmed identity string, or null for anything empty.
     */
    private function cleanIdentity(int|string|null $identity): ?string
    {
        $identityText = trim((string) $identity);

        return $identityText === "" ? null : $identityText;
    }
}
