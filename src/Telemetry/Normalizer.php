<?php

namespace SimpleDataLogs\Laravel\Telemetry;

/**
 * Turns an entry into one bundle line within the wire caps, and formats exceptions, SQL and cache keys safely.
 */
final class Normalizer
{
    // Children per bundle after collapsing; the rest fold into one "(other)" child per kind.
    public const MAX_CHILDREN = 60;

    // Exceptions per bundle.
    public const MAX_EXCEPTIONS = 5;

    // Frames per stack, and the stack's size as JSON.
    public const MAX_FRAMES = 50;
    public const MAX_STACK_BYTES = 8192;

    // Props per object.
    public const MAX_PROPS = 100;

    // A bundle line over this size as JSON is trimmed, never sent in part.
    public const MAX_BUNDLE_BYTES = 65536;

    // Name caps: SQL may be longer than other names.
    public const MAX_NAME_BYTES = 512;
    public const MAX_SQL_BYTES = 2048;

    // Exception messages and string prop values are cut past these.
    private const MAX_MESSAGE_BYTES = 2048;
    private const MAX_PROP_VALUE_BYTES = 1024;

    // The name of the child that holds whatever went past the children cap.
    public const OTHER_NAME = "(other)";

    // Frames under these folders count as the app's own code.
    private const IN_APP_FOLDERS = ["app/", "routes/", "database/"];

    // One encoding for every line: invalid UTF-8 replaced, nothing ever fails.
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * Keeps the app's base path (for relative frames), environment, release and the prop keys never sent.
     *
     * @param array<int, string> $dropPropKeys
     */
    public function __construct(
        private string $basePath,
        private string $appEnvironment,
        private ?string $release = null,
        private array $dropPropKeys = [],
    ) {
        $this->basePath = rtrim($basePath, "/") . "/";
    }

    /**
     * The entry as one JSON line, children only when detailed; null when it can't fit even trimmed.
     */
    public function encodeBundle(Entry $entry, bool $isDetailed, int $sampleWeight = 1): ?string
    {
        $bundle = $this->bundle($entry, $isDetailed, $sampleWeight);
        $bundleJson = json_encode($bundle, self::JSON_FLAGS);

        if (strlen($bundleJson) <= self::MAX_BUNDLE_BYTES) {
            return $bundleJson;
        }

        // Too big: children go first, the summary keeps the totals.
        unset($bundle["spans"]);
        $bundle["props"]["bundle_trimmed"] = true;
        $bundleJson = json_encode($bundle, self::JSON_FLAGS);

        if (strlen($bundleJson) <= self::MAX_BUNDLE_BYTES) {
            return $bundleJson;
        }

        // Still too big: exceptions keep their type and message only.
        foreach ($bundle["exceptions"] ?? [] as $exceptionIndex => $exception) {
            $bundle["exceptions"][$exceptionIndex]["frames"] = [];
        }

        $bundleJson = json_encode($bundle, self::JSON_FLAGS);

        return strlen($bundleJson) <= self::MAX_BUNDLE_BYTES ? $bundleJson : null;
    }

    /**
     * The bundle as an array: root fields, props, children (when detailed), summary and exceptions.
     *
     * @return array<string, mixed>
     */
    public function bundle(Entry $entry, bool $isDetailed, int $sampleWeight = 1): array
    {
        $bundle = [
            "kind" => $entry->kind,
            "event_id" => $entry->eventId,
            "ts" => self::timestamp($entry->startedAt),
            "trace_id" => $entry->traceId,
            "span_id" => $entry->spanId,
        ];

        if ($entry->parentSpanId !== null) {
            $bundle["parent_span_id"] = $entry->parentSpanId;
        }

        $bundle += $this->nameFields($entry->name, self::MAX_NAME_BYTES);
        $bundle["duration_ms"] = round($entry->durationMs ?? 0.0, 3);
        $bundle["status"] = $entry->status;
        $bundle += $entry->fields;

        if ($this->release !== null) {
            $bundle["release"] = $this->release;
        }

        // Only an SDK sampling entry points sets a weight (the UI marks the counts as sampled).
        if ($sampleWeight > 1) {
            $bundle["sample_weight"] = $sampleWeight;
        }

        $bundle["props"] = $this->props(array_merge($entry->props, ["app_env" => $this->appEnvironment]));

        if ($isDetailed && $entry->spans !== []) {
            $bundle["spans"] = array_map(fn (Span $span): array => $this->child($span), $this->cappedChildren($entry->spans));
        }

        if ($entry->summary !== []) {
            $bundle["summary"] = $this->roundedSummary($entry->summary);
        }

        if ($entry->exceptions !== []) {
            $bundle["exceptions"] = array_slice($entry->exceptions, 0, self::MAX_EXCEPTIONS);
        }

        return $bundle;
    }

    /**
     * A standalone `kind: exception` line, for an error reported outside any entry point.
     *
     * @param array<string, mixed> $exception
     */
    public function encodeStandaloneException(array $exception, string $eventId): string
    {
        unset($exception["start_ms"]);

        $line = ["kind" => "exception", "event_id" => $eventId, "ts" => self::timestamp(microtime(true))] + $exception;

        if ($this->release !== null) {
            $line["release"] = $this->release;
        }

        $line["props"] = ["app_env" => $this->appEnvironment];

        return json_encode($line, self::JSON_FLAGS);
    }

    /**
     * One exception for the wire: type, cut message, handled, frames within the caps and an optional fingerprint.
     *
     * @return array<string, mixed>
     */
    public function exception(\Throwable $throwable, bool $isHandled, float $startMs, ?string $fingerprint = null): array
    {
        return [
            "type" => $throwable::class,
            "message" => mb_strcut($throwable->getMessage(), 0, self::MAX_MESSAGE_BYTES),
            "handled" => $isHandled,
            "frames" => $this->frames($throwable),
            "fingerprint" => $fingerprint === null || $fingerprint === "" ? null : mb_strcut($fingerprint, 0, 128),
            "start_ms" => round($startMs, 3),
        ];
    }

    /**
     * The children within the cap: the slowest kept in start order, the rest folded into one "(other)" per kind.
     *
     * @param array<string, Span> $spans
     * @return array<int, Span>
     */
    public function cappedChildren(array $spans): array
    {
        $spans = array_values($spans);

        if (count($spans) <= self::MAX_CHILDREN) {
            return $spans;
        }

        // "(other)" children folded while recording stay the fold targets; copies, so the entry is never changed.
        $otherSpansByKind = [];
        $namedSpans = [];

        foreach ($spans as $span) {
            if ($span->name === self::OTHER_NAME) {
                $otherSpansByKind[$span->kind] = clone $span;
            } else {
                $namedSpans[] = $span;
            }
        }

        // Slowest first, so the fold takes the cheapest children.
        usort($namedSpans, fn (Span $left, Span $right): int => $right->durationMs <=> $left->durationMs);

        // Keep as many as fit beside one "(other)" child per kind that overflows.
        $keptCount = min(self::MAX_CHILDREN, count($namedSpans));

        while ($keptCount > 0 && $keptCount + $this->foldKindCount(array_slice($namedSpans, $keptCount), $otherSpansByKind) > self::MAX_CHILDREN) {
            $keptCount--;
        }

        foreach (array_slice($namedSpans, $keptCount) as $foldedSpan) {
            if (isset($otherSpansByKind[$foldedSpan->kind])) {
                $otherSpansByKind[$foldedSpan->kind]->absorbSpan($foldedSpan);

                continue;
            }

            $otherSpan = new Span($foldedSpan->kind, self::OTHER_NAME, $foldedSpan->startMs, $foldedSpan->durationMs, $foldedSpan->status);
            $otherSpan->count = $foldedSpan->count;
            $otherSpan->maxMs = $foldedSpan->maxMs;
            $otherSpansByKind[$foldedSpan->kind] = $otherSpan;
        }

        // Back in start order, as a waterfall reads them.
        $cappedSpans = array_merge(array_slice($namedSpans, 0, $keptCount), array_values($otherSpansByKind));
        usort($cappedSpans, fn (Span $left, Span $right): int => $left->startMs <=> $right->startMs);

        return $cappedSpans;
    }

    /**
     * Scalar props only, without dropped keys, string values cut, at most 100.
     *
     * @param array<string, mixed> $props
     * @return array<string, string|int|float|bool>
     */
    public function props(array $props): array
    {
        $keptProps = [];

        foreach (array_diff_key($props, array_flip($this->dropPropKeys)) as $propName => $propValue) {
            if (count($keptProps) >= self::MAX_PROPS) {
                break;
            }

            // Backed enums send their value; anything not scalar is skipped.
            if ($propValue instanceof \BackedEnum) {
                $propValue = $propValue->value;
            }

            if (is_string($propValue)) {
                $keptProps[(string) $propName] = mb_strcut($propValue, 0, self::MAX_PROP_VALUE_BYTES);
            } elseif (is_int($propValue) || is_bool($propValue) || (is_float($propValue) && is_finite($propValue))) {
                $keptProps[(string) $propName] = $propValue;
            }
        }

        return $keptProps;
    }

    /**
     * SQL with quoted strings and bare numbers replaced by `?`; bindings are already `?` and never sent.
     */
    public static function maskSqlLiterals(string $sql): string
    {
        // Most queries carry no literal at all: skip the regex then.
        if (strpbrk($sql, "'0123456789") === false) {
            return $sql;
        }

        $maskedSql = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", "?", $sql) ?? $sql;

        return preg_replace("/(?<![\\w$.`\"])-?\\d+(?:\\.\\d+)?(?![\\w$`\"])/", "?", $maskedSql) ?? $maskedSql;
    }

    /**
     * A cache key as a pattern: UUIDs, hex runs, long tokens and digits become `*`.
     */
    public static function maskCacheKey(string $cacheKey): string
    {
        $maskedKey = preg_replace([
            "/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i",
            "/[0-9a-f]{8,}/i",
            "/[A-Za-z0-9+\\/_=-]{24,}/",
            "/\\d+/",
            "/\\*+/",
        ], ["*", "*", "*", "*", "*"], $cacheKey);

        return mb_strcut($maskedKey ?? "*", 0, self::MAX_NAME_BYTES);
    }

    /**
     * An ISO 8601 UTC timestamp with milliseconds for a unix time in seconds.
     */
    public static function timestamp(float $unixSeconds): string
    {
        $moment = \DateTimeImmutable::createFromFormat("U.u", sprintf("%.6F", $unixSeconds)) ?: new \DateTimeImmutable();

        return $moment->format("Y-m-d\\TH:i:s.v\\Z");
    }

    /**
     * One child for the wire.
     *
     * @return array<string, mixed>
     */
    private function child(Span $span): array
    {
        $child = [
            "kind" => $span->kind,
            "span_id" => TraceContext::newSpanId(),
            "start_ms" => round($span->startMs, 3),
            "duration_ms" => round($span->durationMs, 3),
        ];

        $child += $this->nameFields($span->name, $span->kind === "query" ? self::MAX_SQL_BYTES : self::MAX_NAME_BYTES);
        $child["count"] = $span->count;

        // The slowest run matters only when several were folded together.
        if ($span->count > 1) {
            $child["max_ms"] = round($span->maxMs, 3);
        }

        if ($span->kind === "http" || $span->status === "error") {
            $child["status"] = $span->status;
        }

        if ($span->httpStatus !== null) {
            $child["http_status"] = $span->httpStatus;
        }

        $childProps = $this->props($span->props);

        if ($childProps !== []) {
            $child["props"] = $childProps;
        }

        return $child;
    }

    /**
     * The name within its cap, with `name_truncated` when it was cut.
     *
     * @return array<string, string|bool>
     */
    private function nameFields(string $name, int $maxBytes): array
    {
        if (strlen($name) <= $maxBytes) {
            return ["name" => $name];
        }

        return ["name" => mb_strcut($name, 0, $maxBytes), "name_truncated" => true];
    }

    /**
     * The summary with durations rounded to microseconds.
     *
     * @param array<string, array<string, int|float>> $summary
     * @return array<string, array<string, int|float>>
     */
    private function roundedSummary(array $summary): array
    {
        foreach ($summary as $summaryKind => $totals) {
            if (isset($totals["duration_ms"])) {
                $summary[$summaryKind]["duration_ms"] = round($totals["duration_ms"], 3);
            }
        }

        return $summary;
    }

    /**
     * How many "(other)" children there will be: one per kind already folded or about to be.
     *
     * @param array<int, Span> $foldedSpans
     * @param array<string, Span> $otherSpansByKind
     */
    private function foldKindCount(array $foldedSpans, array $otherSpansByKind): int
    {
        $foldKinds = array_merge(array_keys($otherSpansByKind), array_map(fn (Span $span): string => $span->kind, $foldedSpans));

        return count(array_unique($foldKinds));
    }

    /**
     * The stack innermost first: the throw site, then each caller, at most 50 frames and 8 KB, never arguments.
     *
     * @return array<int, array<string, mixed>>
     */
    private function frames(\Throwable $throwable): array
    {
        $traceFrames = $throwable->getTrace();

        // The throw site runs inside the first trace frame's function; each later frame's call site inside the next one's.
        $callSites = [["file" => $throwable->getFile(), "line" => $throwable->getLine()]];

        foreach ($traceFrames as $traceFrame) {
            $callSites[] = ["file" => $traceFrame["file"] ?? null, "line" => $traceFrame["line"] ?? null];
        }

        $frames = [];
        $stackBytes = 2;

        foreach ($callSites as $frameIndex => $callSite) {
            if (count($frames) >= self::MAX_FRAMES) {
                break;
            }

            $frame = $this->frame($callSite["file"], $callSite["line"], $traceFrames[$frameIndex] ?? null);
            $frameBytes = strlen(json_encode($frame, self::JSON_FLAGS)) + 1;

            if ($stackBytes + $frameBytes > self::MAX_STACK_BYTES) {
                break;
            }

            $frames[] = $frame;
            $stackBytes += $frameBytes;
        }

        return $frames;
    }

    /**
     * One frame: the file relative to the app, the line, the function and whether it is the app's own code.
     *
     * @param array<string, mixed>|null $enclosingFrame
     * @return array<string, mixed>
     */
    private function frame(?string $filePath, ?int $lineNumber, ?array $enclosingFrame): array
    {
        $relativePath = $filePath === null ? "[internal]" : $this->relativePath($filePath);
        $functionName = "{main}";

        if ($enclosingFrame !== null) {
            $functionName = isset($enclosingFrame["class"])
                ? $enclosingFrame["class"] . "::" . $enclosingFrame["function"]
                : (string) $enclosingFrame["function"];
        }

        $isInApp = ! str_starts_with($relativePath, "vendor/") && array_filter(
            self::IN_APP_FOLDERS,
            fn (string $inAppFolder): bool => str_starts_with($relativePath, $inAppFolder),
        ) !== [];

        return ["file" => $relativePath, "line" => (int) $lineNumber, "function" => mb_strcut($functionName, 0, 256), "in_app" => $isInApp];
    }

    /**
     * A path relative to the app's base folder; paths outside it stay as they are.
     */
    private function relativePath(string $filePath): string
    {
        return str_starts_with($filePath, $this->basePath) ? substr($filePath, strlen($this->basePath)) : $filePath;
    }
}
