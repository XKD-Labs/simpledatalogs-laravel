<?php

namespace SimpleDataLogs\Laravel\Tests\Telemetry\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * A queued job that throws on its only attempt.
 */
class FailingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // One attempt, so the first exception fails the job.
    public int $tries = 1;

    /**
     * Throws.
     */
    public function handle(): void
    {
        throw new \RuntimeException("Payment provider timed out");
    }
}
