<?php

namespace SimpleDataLogs\Laravel\Telemetry;

/**
 * One child of a root (a query, an outgoing call, a cache outcome, a mail, a custom span), with identical ones folded in.
 */
final class Span
{
    // How many identical children this one stands for.
    public int $count = 1;

    // The slowest single run among them, in milliseconds.
    public float $maxMs;

    /**
     * Keeps the child's kind, name, offset from the root's start, duration, status and props.
     *
     * @param array<string, mixed> $props
     */
    public function __construct(
        public string $kind,
        public string $name,
        public float $startMs,
        public float $durationMs,
        public string $status = "ok",
        public array $props = [],
        public ?int $httpStatus = null,
    ) {
        $this->maxMs = $durationMs;
    }

    /**
     * Folds one more identical run in: the count grows, durations add up and the slowest is kept.
     */
    public function absorb(float $durationMs, string $status): void
    {
        $this->count++;
        $this->durationMs += $durationMs;
        $this->maxMs = max($this->maxMs, $durationMs);

        if ($status === "error") {
            $this->status = "error";
        }
    }

    /**
     * Folds another child in whole, for the "(other)" child that holds what went past the cap.
     */
    public function absorbSpan(Span $otherSpan): void
    {
        $this->count += $otherSpan->count;
        $this->durationMs += $otherSpan->durationMs;
        $this->maxMs = max($this->maxMs, $otherSpan->maxMs);
        $this->startMs = min($this->startMs, $otherSpan->startMs);

        if ($otherSpan->status === "error") {
            $this->status = "error";
        }
    }
}
