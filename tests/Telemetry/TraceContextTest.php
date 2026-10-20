<?php

namespace SimpleDataLogs\Laravel\Tests\Telemetry;

use SimpleDataLogs\Laravel\Telemetry\TraceContext;

use PHPUnit\Framework\TestCase;

/**
 * W3C trace ids: generated ids, and incoming `traceparent` headers and job payloads read safely.
 */
class TraceContextTest extends TestCase
{
    public function test_new_ids_are_random_lowercase_hex_of_the_w3c_lengths(): void
    {
        $this->assertTrue(TraceContext::isTraceId(TraceContext::newTraceId()));
        $this->assertTrue(TraceContext::isSpanId(TraceContext::newSpanId()));
        $this->assertNotSame(TraceContext::newTraceId(), TraceContext::newTraceId());
    }

    public function test_a_valid_traceparent_gives_the_trace_and_parent_span(): void
    {
        $this->assertSame(
            ["trace_id" => "4bf92f3577b34da6a3ce929d0e0e4736", "parent_span_id" => "00f067aa0ba902b7"],
            TraceContext::fromTraceparent(" 00-4BF92F3577B34DA6A3CE929D0E0E4736-00F067AA0BA902B7-01 "),
        );

        $this->assertSame("00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01", TraceContext::traceparent("4bf92f3577b34da6a3ce929d0e0e4736", "00f067aa0ba902b7"));
    }

    public function test_invalid_traceparents_are_ignored(): void
    {
        foreach ([
            null,
            "",
            "garbage",
            "ff-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01",
            "00-00000000000000000000000000000000-00f067aa0ba902b7-01",
            "00-4bf92f3577b34da6a3ce929d0e0e4736-0000000000000000-01",
            "00-4bf92f3577b34da6a3ce929d0e0e473-00f067aa0ba902b7-01",
        ] as $invalidHeader) {
            $this->assertNull(TraceContext::fromTraceparent($invalidHeader), var_export($invalidHeader, true));
        }
    }

    public function test_a_job_payload_trace_is_read_only_when_well_formed(): void
    {
        $this->assertSame(
            ["trace_id" => "4bf92f3577b34da6a3ce929d0e0e4736", "parent_span_id" => "00f067aa0ba902b7"],
            TraceContext::fromPayload(["trace_id" => "4bf92f3577b34da6a3ce929d0e0e4736", "span_id" => "00f067aa0ba902b7"]),
        );

        $this->assertNull(TraceContext::fromPayload(null));
        $this->assertNull(TraceContext::fromPayload(["trace_id" => "nope", "span_id" => "00f067aa0ba902b7"]));
    }
}
