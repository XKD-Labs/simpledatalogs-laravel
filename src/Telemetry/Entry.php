<?php

namespace SimpleDataLogs\Laravel\Telemetry;

/**
 * One entry point being recorded (a request, job, scheduled task or command): the root of one bundle.
 */
final class Entry
{
    // The bundle's event id, so a resent bundle counts once.
    public string $eventId;

    // The root's own span id inside the trace.
    public string $spanId;

    // "ok" or "error".
    public string $status = "ok";

    // Set once the entry ends; null while it runs.
    public ?float $durationMs = null;

    // True once the entry ended (a request waits for termination before it is written).
    public bool $isEnded = false;

    // Root fields beside the name: http_status, method, route, user_id.
    /** @var array<string, string|int> */
    public array $fields = [];

    // The root's props (queue, attempts, exit code, memory).
    /** @var array<string, mixed> */
    public array $props = [];

    // Children by collapse key, so identical ones fold into one.
    /** @var array<string, Span> */
    public array $spans = [];

    // Exact totals per kind, kept even when children are sampled away.
    /** @var array<string, array<string, int|float>> */
    public array $summary = [];

    // Exceptions already formatted for the wire, at most five.
    /** @var array<int, array<string, mixed>> */
    public array $exceptions = [];

    // Each recorded throwable's position in $exceptions, so one reported twice is kept once.
    /** @var \WeakMap<\Throwable, int> */
    public \WeakMap $exceptionPositions;

    // What the end event is matched on: the job object, the task object or the command name.
    public mixed $identity = null;

    /**
     * Opens the entry with its kind, name, start time (unix seconds) and trace.
     */
    public function __construct(
        public string $kind,
        public string $name,
        public float $startedAt,
        public string $traceId,
        public ?string $parentSpanId = null,
    ) {
        $this->spanId = TraceContext::newSpanId();
        $this->eventId = self::newEventId();
        $this->exceptionPositions = new \WeakMap();
    }

    /**
     * Milliseconds from the entry's start to a moment (unix seconds).
     */
    public function offsetMs(float $moment): float
    {
        return max(($moment - $this->startedAt) * 1000, 0.0);
    }

    /**
     * Fixes the duration as of now, unless it was already fixed.
     */
    public function end(): void
    {
        $this->durationMs ??= $this->offsetMs(microtime(true));
        $this->isEnded = true;
    }

    /**
     * True when at least one recorded exception was not handled by the app.
     */
    public function hasUnhandledException(): bool
    {
        foreach ($this->exceptions as $exception) {
            if ($exception["handled"] === false) {
                return true;
            }
        }

        return false;
    }

    /**
     * A random UUID (version 4) without touching the container.
     */
    public static function newEventId(): string
    {
        $randomBytes = random_bytes(16);
        $randomBytes[6] = chr((ord($randomBytes[6]) & 0x0f) | 0x40);
        $randomBytes[8] = chr((ord($randomBytes[8]) & 0x3f) | 0x80);

        return vsprintf("%s%s-%s-%s-%s-%s%s%s", str_split(bin2hex($randomBytes), 4));
    }
}
