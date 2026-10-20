<?php

namespace SimpleDataLogs\Laravel\Telemetry;

use SimpleDataLogs\Laravel\Outbox;

/**
 * Holds the entry points being recorded in memory and writes one bundle line per entry point to the outbox when it ends.
 * Every public method fails open: telemetry never throws into the app.
 */
final class Recorder
{
    // Distinct children one entry keeps while recording; past this, new ones fold into "(other)" so a loop never grows memory.
    private const MAX_CHILDREN_IN_MEMORY = 500;

    // The summary key for each child kind (cache keeps outcome counts instead).
    private const SUMMARY_KEYS = ["query" => "queries", "http" => "http", "mail" => "mail", "notification" => "notifications", "span" => "spans"];

    // The summary counter for each cache outcome.
    private const CACHE_SUMMARY_KEYS = ["hit" => "hits", "miss" => "misses", "write" => "writes", "forget" => "forgets"];

    // Entries still running, innermost last (a sync job inside a request sits above it).
    /** @var array<int, Entry> */
    private array $openEntries = [];

    // Requests that ended and wait for termination to be written.
    /** @var array<int, Entry> */
    private array $endedEntries = [];

    // Exceptions already sent inside a bundle, so the report that follows a failed job is not sent again on its own.
    /** @var \WeakMap<\Throwable, true> */
    private \WeakMap $sentExceptions;

    /**
     * Keeps the outbox, the sampler, the normalizer, the cache-key switch and an optional fingerprint callback.
     *
     * @param \Closure(\Throwable): (string|null)|null $fingerprintResolver
     */
    public function __construct(
        private Outbox $outbox,
        private Sampler $sampler,
        private Normalizer $normalizer,
        private bool $shouldRecordCacheKeys = false,
        private ?\Closure $fingerprintResolver = null,
    ) {
        $this->sentExceptions = new \WeakMap();
    }

    /**
     * Opens an entry point; it joins the trace of the entry around it, or the remote parent given.
     *
     * @param array{trace_id: string, parent_span_id: string}|null $remoteParent
     */
    public function begin(string $entryKind, string $entryName, ?array $remoteParent = null, ?float $startedAt = null, mixed $identity = null): Entry
    {
        $enclosingEntry = $this->current();
        $traceId = $remoteParent["trace_id"] ?? $enclosingEntry?->traceId ?? TraceContext::newTraceId();
        $parentSpanId = $remoteParent["parent_span_id"] ?? $enclosingEntry?->spanId;

        $entry = new Entry($entryKind, $entryName, $startedAt ?? microtime(true), $traceId, $parentSpanId);
        $entry->identity = $identity;
        $this->openEntries[] = $entry;

        return $entry;
    }

    /**
     * The running request, or a new one; an incoming `traceparent` joins the caller's trace.
     */
    public function beginRequest(?float $startedAt = null, mixed $traceparentHeader = null): ?Entry
    {
        try {
            $remoteParent = TraceContext::fromTraceparent($traceparentHeader);
            $runningRequest = $this->openEntry("request");

            // Begun earlier in this request (at boot): only the caller's trace is applied.
            if ($runningRequest !== null) {
                if ($remoteParent !== null) {
                    $runningRequest->traceId = $remoteParent["trace_id"];
                    $runningRequest->parentSpanId = $remoteParent["parent_span_id"];
                }

                return $runningRequest;
            }

            return $this->begin("request", "request", $remoteParent, $startedAt);
        } catch (\Throwable $error) {
            $this->logFailure("a request was not recorded", $error);

            return null;
        }
    }

    /**
     * The innermost running entry, or null between entry points.
     */
    public function current(): ?Entry
    {
        return $this->openEntries === [] ? null : $this->openEntries[array_key_last($this->openEntries)];
    }

    /**
     * The innermost running entry of a kind, optionally matched on its identity.
     */
    public function openEntry(string $entryKind, mixed $identity = null): ?Entry
    {
        for ($entryIndex = count($this->openEntries) - 1; $entryIndex >= 0; $entryIndex--) {
            $entry = $this->openEntries[$entryIndex];

            if ($entry->kind === $entryKind && ($identity === null || $entry->identity === $identity)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Ends an entry and keeps it until termination (requests: the response is out first).
     */
    public function park(Entry $entry): void
    {
        try {
            $entry->end();
            $this->removeOpen($entry);
            $this->endedEntries[] = $entry;
        } catch (\Throwable $error) {
            $this->logFailure("a request was not ended", $error);
        }
    }

    /**
     * Ends an entry and writes its bundle now (jobs, tasks and commands).
     */
    public function finish(Entry $entry): void
    {
        try {
            $entry->end();
            $this->removeOpen($entry);
            $this->write($entry);
        } catch (\Throwable $error) {
            $this->logFailure("a {$entry->kind} was not ended", $error);
        }
    }

    /**
     * Writes every ended entry, then ends and writes anything still running (termination).
     */
    public function flush(): void
    {
        try {
            $endedEntries = $this->endedEntries;
            $this->endedEntries = [];

            foreach ($endedEntries as $endedEntry) {
                $this->write($endedEntry);
            }

            // Anything still open never saw its end event: an uncaught error is the usual reason.
            while (($openEntry = array_pop($this->openEntries)) !== null) {
                if ($openEntry->exceptions !== []) {
                    $openEntry->status = "error";
                    $openEntry->exceptions[array_key_last($openEntry->exceptions)]["handled"] = false;
                }

                $openEntry->end();
                $this->write($openEntry);
            }
        } catch (\Throwable $error) {
            $this->logFailure("telemetry was not flushed", $error);
        }
    }

    /**
     * Drops whatever is in memory without writing (a long-lived worker starting fresh after a crash).
     */
    public function reset(): void
    {
        $this->openEntries = [];
        $this->endedEntries = [];
    }

    /**
     * Records one query under the innermost entry: SQL with `?` placeholders, folded with identical ones.
     */
    public function recordQuery(string $sql, float $durationMs, ?string $connectionName): void
    {
        $entry = $this->current();

        if ($entry === null) {
            return;
        }

        try {
            $maskedSql = Normalizer::maskSqlLiterals($sql);
            $startMs = $entry->offsetMs(microtime(true)) - $durationMs;

            $this->addChild($entry, "query", $maskedSql, $durationMs, $startMs, ["connection" => (string) $connectionName], "ok", null, (string) $connectionName);
        } catch (\Throwable $error) {
            $this->logFailure("a query was not recorded", $error);
        }
    }

    /**
     * Records one outgoing HTTP call as `METHOD host`, with its status.
     */
    public function recordHttp(string $method, string $host, float $durationMs, ?float $startedAt, ?int $httpStatus): void
    {
        $entry = $this->current();

        if ($entry === null) {
            return;
        }

        try {
            $startMs = $startedAt !== null ? $entry->offsetMs($startedAt) : $entry->offsetMs(microtime(true)) - $durationMs;
            $status = $httpStatus === null || $httpStatus >= 500 ? "error" : "ok";
            $callName = strtoupper($method) . " " . ($host === "" ? "unknown" : strtolower($host));

            $this->addChild($entry, "http", $callName, $durationMs, $startMs, [], $status, $httpStatus, (string) ($httpStatus ?? "failed"));
        } catch (\Throwable $error) {
            $this->logFailure("an outgoing call was not recorded", $error);
        }
    }

    /**
     * Counts one cache outcome (hit, miss, write, forget) per store; the key pattern only when cache keys are on.
     */
    public function recordCache(string $outcome, ?string $storeName, mixed $cacheKey): void
    {
        $entry = $this->current();

        if ($entry === null || ! isset(self::CACHE_SUMMARY_KEYS[$outcome])) {
            return;
        }

        try {
            $summaryKey = self::CACHE_SUMMARY_KEYS[$outcome];
            $entry->summary["cache"][$summaryKey] = ($entry->summary["cache"][$summaryKey] ?? 0) + 1;

            $cacheProps = ["store" => (string) $storeName];

            if ($this->shouldRecordCacheKeys && is_string($cacheKey)) {
                $cacheProps["key"] = Normalizer::maskCacheKey($cacheKey);
            }

            $this->addChild($entry, "cache", $outcome, 0.0, $entry->offsetMs(microtime(true)), $cacheProps, "ok", null, implode("\0", $cacheProps), false);
        } catch (\Throwable $error) {
            $this->logFailure("a cache event was not recorded", $error);
        }
    }

    /**
     * Records a finished child that started at a known moment (mail, notifications).
     *
     * @param array<string, mixed> $props
     */
    public function recordTimedChild(string $childKind, string $childName, float $startedAt, array $props = [], string $status = "ok"): void
    {
        $entry = $this->current();

        if ($entry === null) {
            return;
        }

        try {
            $durationMs = max((microtime(true) - $startedAt) * 1000, 0.0);

            $this->addChild($entry, $childKind, $childName, $durationMs, $entry->offsetMs($startedAt), $props, $status, null, implode("\0", array_map("strval", $props)));
        } catch (\Throwable $error) {
            $this->logFailure("a {$childKind} was not recorded", $error);
        }
    }

    /**
     * Runs a callback as a custom child span and returns its result; the app's own exceptions still reach the app.
     *
     * @template TResult
     * @param callable(): TResult $callback
     * @param array<string, mixed> $props
     * @return TResult
     */
    public function span(string $spanName, callable $callback, array $props = []): mixed
    {
        $startedAt = microtime(true);
        $status = "error";

        try {
            $result = $callback();
            $status = "ok";

            return $result;
        } finally {
            $this->recordTimedChild("span", $spanName, $startedAt, $props, $status);
        }
    }

    /**
     * Records an exception under the innermost entry, or sends it on its own outside any entry point.
     */
    public function recordException(\Throwable $throwable, bool $isHandled = true): void
    {
        try {
            $entry = $this->current();

            if ($entry === null) {
                $this->writeStandaloneException($throwable, $isHandled);

                return;
            }

            $this->addException($entry, $throwable, $isHandled);
        } catch (\Throwable $error) {
            $this->logFailure("an exception was not recorded", $error);
        }
    }

    /**
     * Marks a recorded exception as not handled by the app (a 5xx response, a failed job), adding it when asked.
     */
    public function markUnhandled(Entry $entry, mixed $throwable, bool $shouldAddWhenMissing): void
    {
        if (! $throwable instanceof \Throwable) {
            return;
        }

        try {
            if (isset($entry->exceptionPositions[$throwable])) {
                $entry->exceptions[$entry->exceptionPositions[$throwable]]["handled"] = false;
            } elseif ($shouldAddWhenMissing) {
                $this->addException($entry, $throwable, false);
            }
        } catch (\Throwable $error) {
            $this->logFailure("an exception was not marked", $error);
        }
    }

    /**
     * The trace id and the root's span id of the innermost entry, for log lines; null between entry points.
     *
     * @return array{trace_id: string, span_id: string}|null
     */
    public function currentTraceIds(): ?array
    {
        $entry = $this->current();

        return $entry === null ? null : ["trace_id" => $entry->traceId, "span_id" => $entry->spanId];
    }

    /**
     * The `__sdl_trace` payload key a queued job carries, so the worker's job joins this trace.
     *
     * @return array<string, array{trace_id: string, span_id: string}>
     */
    public function payloadTrace(): array
    {
        $traceIds = $this->currentTraceIds();

        return $traceIds === null ? [] : ["__sdl_trace" => $traceIds];
    }

    /**
     * Adds a child to an entry, folding it into an identical one; past the memory cap it folds into "(other)".
     *
     * @param array<string, mixed> $props
     */
    private function addChild(
        Entry $entry,
        string $childKind,
        string $childName,
        float $durationMs,
        float $startMs,
        array $props,
        string $status = "ok",
        ?int $httpStatus = null,
        string $collapseDetail = "",
        bool $shouldCountInSummary = true,
    ): void {
        // Exact totals first, whatever happens to the child itself.
        if ($shouldCountInSummary && isset(self::SUMMARY_KEYS[$childKind])) {
            $summaryKey = self::SUMMARY_KEYS[$childKind];
            $entry->summary[$summaryKey]["count"] = ($entry->summary[$summaryKey]["count"] ?? 0) + 1;
            $entry->summary[$summaryKey]["duration_ms"] = ($entry->summary[$summaryKey]["duration_ms"] ?? 0.0) + $durationMs;
        }

        $collapseKey = $childKind . "\0" . $childName . "\0" . $collapseDetail;

        if (isset($entry->spans[$collapseKey])) {
            $entry->spans[$collapseKey]->absorb($durationMs, $status);

            return;
        }

        // Too many distinct children in memory: fold the new one into this kind's "(other)".
        if (count($entry->spans) >= self::MAX_CHILDREN_IN_MEMORY) {
            $otherKey = $childKind . "\0" . Normalizer::OTHER_NAME . "\0";

            if (isset($entry->spans[$otherKey])) {
                $entry->spans[$otherKey]->absorb($durationMs, $status);
            } else {
                $entry->spans[$otherKey] = new Span($childKind, Normalizer::OTHER_NAME, max($startMs, 0.0), $durationMs, $status);
            }

            return;
        }

        $entry->spans[$collapseKey] = new Span($childKind, $childName, max($startMs, 0.0), $durationMs, $status, $props, $httpStatus);
    }

    /**
     * Formats and keeps an exception on an entry, at most five; a sixth only counts.
     */
    private function addException(Entry $entry, \Throwable $throwable, bool $isHandled): void
    {
        if (isset($entry->exceptionPositions[$throwable])) {
            return;
        }

        if (count($entry->exceptions) >= Normalizer::MAX_EXCEPTIONS) {
            $entry->props["exceptions_dropped"] = ($entry->props["exceptions_dropped"] ?? 0) + 1;

            return;
        }

        $entry->exceptions[] = $this->normalizer->exception($throwable, $isHandled, $entry->offsetMs(microtime(true)), $this->fingerprint($throwable));
        $entry->exceptionPositions[$throwable] = array_key_last($entry->exceptions);
    }

    /**
     * Sends an exception reported outside any entry point as a `kind: exception` line, unless a bundle already carried it.
     */
    private function writeStandaloneException(\Throwable $throwable, bool $isHandled): void
    {
        if (isset($this->sentExceptions[$throwable])) {
            return;
        }

        $exception = $this->normalizer->exception($throwable, $isHandled, 0.0, $this->fingerprint($throwable));
        $this->outbox->append($this->normalizer->encodeStandaloneException($exception, Entry::newEventId()));
        $this->sentExceptions[$throwable] = true;
    }

    /**
     * The app's own fingerprint for an exception, when a callback is configured.
     */
    private function fingerprint(\Throwable $throwable): ?string
    {
        if ($this->fingerprintResolver === null) {
            return null;
        }

        try {
            $fingerprint = ($this->fingerprintResolver)($throwable);

            return is_string($fingerprint) || is_int($fingerprint) ? (string) $fingerprint : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Samples, encodes once and appends one bundle line; the sampler may drop it or keep only the summary.
     */
    private function write(Entry $entry): void
    {
        try {
            if ($entry->hasUnhandledException()) {
                $entry->status = "error";
            }

            $decision = $this->sampler->decide($entry->kind, $entry->durationMs ?? 0.0, $entry->status === "error");

            if (! $decision["is_kept"]) {
                return;
            }

            $bundleLine = $this->normalizer->encodeBundle($entry, $decision["is_detailed"], $decision["sample_weight"]);

            if ($bundleLine === null) {
                error_log("[simpledatalogs] a {$entry->kind} bundle stayed over 64 KB after trimming and was skipped");

                return;
            }

            $this->outbox->append($bundleLine);

            // The report that follows a failed job or task must not send these again.
            foreach ($entry->exceptionPositions as $sentThrowable => $exceptionPosition) {
                $this->sentExceptions[$sentThrowable] = true;
            }
        } catch (\Throwable $error) {
            $this->logFailure("a {$entry->kind} bundle was not queued", $error);
        }
    }

    /**
     * Takes an entry off the running stack.
     */
    private function removeOpen(Entry $entry): void
    {
        foreach ($this->openEntries as $entryIndex => $openEntry) {
            if ($openEntry === $entry) {
                array_splice($this->openEntries, $entryIndex, 1);

                return;
            }
        }
    }

    /**
     * Writes a telemetry failure to PHP's error log; the app carries on.
     */
    private function logFailure(string $whatFailed, \Throwable $error): void
    {
        error_log("[simpledatalogs] {$whatFailed}: " . $error->getMessage());
    }
}
