<?php

namespace SimpleDataLogs\Laravel\Telemetry;

/**
 * Decides per entry point whether it is sent, with which weight, and whether its children go too.
 */
final class Sampler
{
    // Returns a float in [0, 1); swappable so tests can pin the dice.
    private \Closure $randomizer;

    /**
     * Keeps the slow thresholds, how often a plain root sends detail, and the entry-point sample rate.
     *
     * @param \Closure(): float|null $randomizer
     */
    public function __construct(
        private int $slowRequestMs = 500,
        private int $slowJobMs = 5000,
        private int $detailEvery = 10,
        private float $sampleRate = 1.0,
        ?\Closure $randomizer = null,
    ) {
        $this->sampleRate = min(max($sampleRate, 0.0), 1.0);
        $this->randomizer = $randomizer ?? fn (): float => mt_rand() / (mt_getrandmax() + 1);
    }

    /**
     * Keep or drop, the sample weight, and detail or summary only.
     *
     * @return array{is_kept: bool, sample_weight: int, is_detailed: bool}
     */
    public function decide(string $entryKind, float $durationMs, bool $isErrored): array
    {
        // Requests use the request threshold; jobs, tasks and commands the job one.
        $slowThresholdMs = $entryKind === "request" ? $this->slowRequestMs : $this->slowJobMs;
        $isSlow = $durationMs > $slowThresholdMs;
        $sampleWeight = 1;

        // Entry-point sampling never drops a slow or errored root.
        if (! $isSlow && ! $isErrored && $this->sampleRate < 1.0) {
            if ($this->sampleRate <= 0.0 || ($this->randomizer)() >= $this->sampleRate) {
                return ["is_kept" => false, "sample_weight" => 0, "is_detailed" => false];
            }

            $sampleWeight = max(1, (int) round(1 / $this->sampleRate));
        }

        // Slow and errored roots always send their children; the rest one in detail_every.
        $isDetailed = $isSlow || $isErrored || $this->detailEvery <= 1 || ($this->randomizer)() < 1 / $this->detailEvery;

        return ["is_kept" => true, "sample_weight" => $sampleWeight, "is_detailed" => $isDetailed];
    }
}
