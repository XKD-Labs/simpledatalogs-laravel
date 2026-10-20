<?php

namespace SimpleDataLogs\Laravel\Telemetry;

/**
 * W3C trace context: random trace and span ids, and a safe read of an incoming `traceparent` header.
 */
final class TraceContext
{
    // version-traceid-spanid-flags, lowercase hex (W3C Trace Context level 1).
    private const TRACEPARENT_PATTERN = "/^([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/";

    /**
     * A new 32-hex trace id from 16 random bytes.
     */
    public static function newTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * A new 16-hex span id from 8 random bytes.
     */
    public static function newSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * The trace id and parent span id of a valid `traceparent` header; null for anything else.
     *
     * @return array{trace_id: string, parent_span_id: string}|null
     */
    public static function fromTraceparent(mixed $traceparentHeader): ?array
    {
        if (! is_string($traceparentHeader) || $traceparentHeader === "") {
            return null;
        }

        $isMatched = preg_match(self::TRACEPARENT_PATTERN, strtolower(trim($traceparentHeader)), $headerParts) === 1;

        // Version ff is reserved as invalid, and all-zero ids mean "no trace".
        if (! $isMatched || $headerParts[1] === "ff" || ! self::isTraceId($headerParts[2]) || ! self::isSpanId($headerParts[3])) {
            return null;
        }

        return ["trace_id" => $headerParts[2], "parent_span_id" => $headerParts[3]];
    }

    /**
     * The trace id and parent span id carried in a queued job's payload; null when missing or malformed.
     *
     * @return array{trace_id: string, parent_span_id: string}|null
     */
    public static function fromPayload(mixed $payloadTrace): ?array
    {
        if (! is_array($payloadTrace) || ! self::isTraceId($payloadTrace["trace_id"] ?? null) || ! self::isSpanId($payloadTrace["span_id"] ?? null)) {
            return null;
        }

        return ["trace_id" => $payloadTrace["trace_id"], "parent_span_id" => $payloadTrace["span_id"]];
    }

    /**
     * True for 32 lowercase hex characters that are not all zeros.
     */
    public static function isTraceId(mixed $candidate): bool
    {
        return is_string($candidate) && preg_match("/^[0-9a-f]{32}$/", $candidate) === 1 && trim($candidate, "0") !== "";
    }

    /**
     * True for 16 lowercase hex characters that are not all zeros.
     */
    public static function isSpanId(mixed $candidate): bool
    {
        return is_string($candidate) && preg_match("/^[0-9a-f]{16}$/", $candidate) === 1 && trim($candidate, "0") !== "";
    }

    /**
     * The `traceparent` header value for a span, sampled flag set.
     */
    public static function traceparent(string $traceId, string $spanId): string
    {
        return "00-{$traceId}-{$spanId}-01";
    }
}
