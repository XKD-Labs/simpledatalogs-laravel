<?php

namespace SimpleDataLogs\Laravel\Tests;

use SimpleDataLogs\Laravel\Outbox;

/**
 * The append/claim race (reliability review, 2026-10-16): a line written after its file was claimed and acknowledged must never vanish.
 */
class OutboxRaceTest extends TestCase
{
    public function test_a_claim_between_open_and_lock_sends_the_line_to_a_fresh_pending_file(): void
    {
        // An outbox whose writer is interrupted by a whole ship cycle after opening its file.
        $outbox = new class ($this->outboxPath) extends Outbox {
            public bool $hasInterrupted = false;

            protected function afterOpen(string $pendingPath): void
            {
                if ($this->hasInterrupted) {
                    return;
                }

                $this->hasInterrupted = true;

                foreach ($this->claim() as $sendingPath) {
                    $this->lines($sendingPath);
                    $this->keep($sendingPath, []);
                }
            }
        };

        // An earlier line, so the interrupted writer's file exists and gets claimed.
        $outbox->append('{"event":"earlier"}');
        $outbox->hasInterrupted = false;

        $outbox->append('{"event":"racing"}');

        $remainingLines = [];

        foreach ($outbox->claim() as $sendingPath) {
            $remainingLines = array_merge($remainingLines, $outbox->lines($sendingPath));
        }

        $this->assertSame(['{"event":"racing"}'], $remainingLines);
    }

    public function test_concurrent_writers_and_a_shipper_lose_nothing(): void
    {
        if (! function_exists("pcntl_fork")) {
            $this->markTestSkipped("pcntl is not available in this PHP build");
        }

        $outbox = new Outbox($this->outboxPath);
        $writerCount = 4;
        $linesPerWriter = 2000;
        $writerPids = [];

        // Writers append from their own processes, as PHP-FPM workers do.
        foreach (range(1, $writerCount) as $writerNumber) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                foreach (range(1, $linesPerWriter) as $lineNumber) {
                    $outbox->append(json_encode(["w" => $writerNumber, "n" => $lineNumber]));
                }

                exit(0);
            }

            $writerPids[] = $pid;
        }

        // The shipper claims, reads and acknowledges while they write.
        $shippedCount = 0;

        do {
            $stillRunning = array_filter($writerPids, fn (int $pid): bool => pcntl_waitpid($pid, $status, WNOHANG) === 0);

            foreach ($outbox->claim() as $sendingPath) {
                $shippedCount += count($outbox->lines($sendingPath));
                $outbox->keep($sendingPath, []);
            }

            $writerPids = array_values($stillRunning);
        } while ($writerPids !== []);

        foreach ($outbox->claim() as $sendingPath) {
            $shippedCount += count($outbox->lines($sendingPath));
            $outbox->keep($sendingPath, []);
        }

        $this->assertSame($writerCount * $linesPerWriter, $shippedCount);
    }
}
