<?php

namespace SimpleDataLogs\Laravel\Tests\Telemetry;

use SimpleDataLogs\Laravel\Telemetry\Sampler;

use PHPUnit\Framework\TestCase;

/**
 * Detail for slow, errored and every nth root; entry-point sampling with a weight that never drops slow or errored roots.
 */
class SamplerTest extends TestCase
{
    /**
     * A sampler whose dice always land on the given value.
     */
    private function sampler(float $diceValue, float $sampleRate = 1.0): Sampler
    {
        return new Sampler(slowRequestMs: 500, slowJobMs: 5000, detailEvery: 10, sampleRate: $sampleRate, randomizer: fn (): float => $diceValue);
    }

    public function test_slow_errored_and_one_in_ten_roots_send_detail_and_the_rest_summary_only(): void
    {
        $unluckySampler = $this->sampler(0.5);

        $this->assertFalse($unluckySampler->decide("request", 120.0, false)["is_detailed"]);
        $this->assertTrue($unluckySampler->decide("request", 501.0, false)["is_detailed"]);
        $this->assertTrue($unluckySampler->decide("request", 12.0, true)["is_detailed"]);

        // Jobs, tasks and commands use the job threshold.
        $this->assertFalse($unluckySampler->decide("job", 4000.0, false)["is_detailed"]);
        $this->assertTrue($unluckySampler->decide("task", 5001.0, false)["is_detailed"]);

        // The one-in-ten dice.
        $this->assertTrue($this->sampler(0.05)->decide("request", 120.0, false)["is_detailed"]);

        // Every root is sent either way.
        $this->assertSame(["is_kept" => true, "sample_weight" => 1, "is_detailed" => false], $unluckySampler->decide("command", 1.0, false));
    }

    public function test_entry_point_sampling_keeps_one_in_ten_with_weight_ten_and_always_keeps_slow_and_errored(): void
    {
        $this->assertSame(["is_kept" => false, "sample_weight" => 0, "is_detailed" => false], $this->sampler(0.5, 0.1)->decide("request", 50.0, false));

        $keptDecision = $this->sampler(0.05, 0.1)->decide("request", 50.0, false);
        $this->assertTrue($keptDecision["is_kept"]);
        $this->assertSame(10, $keptDecision["sample_weight"]);

        $this->assertSame(["is_kept" => true, "sample_weight" => 1, "is_detailed" => true], $this->sampler(0.99, 0.1)->decide("request", 900.0, false));
        $this->assertSame(["is_kept" => true, "sample_weight" => 1, "is_detailed" => true], $this->sampler(0.99, 0.1)->decide("job", 10.0, true));
    }
}
