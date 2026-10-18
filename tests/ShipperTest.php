<?php

namespace SimpleDataLogs\Laravel\Tests;

use SimpleDataLogs\Laravel\Outbox;
use SimpleDataLogs\Laravel\Shipper;
use SimpleDataLogs\Laravel\Transport;

use PHPUnit\Framework\TestCase as PlainTestCase;

/**
 * The shipper's ingest API rules without Laravel: acknowledge only 202, split on 413, pause on 401, back off and keep lines otherwise, streaming big files.
 */
class ShipperTest extends PlainTestCase
{
    // The outbox folder of the running test.
    private string $outboxPath;

    // The fake clock, in seconds.
    private int $now = 1_800_000_000;

    // Every sleep the shipper asked for.
    private array $sleeps = [];

    /**
     * Gives every test a fresh outbox.
     */
    protected function setUp(): void
    {
        $this->outboxPath = sys_get_temp_dir() . "/sdl-shipper-" . bin2hex(random_bytes(6));
    }

    /**
     * Removes the test's outbox.
     */
    protected function tearDown(): void
    {
        exec("rm -rf " . escapeshellarg($this->outboxPath));
    }

    /**
     * An outbox holding the given number of small lines.
     */
    private function outboxWithLines(int $lineCount, int $lineBytes = 20): Outbox
    {
        $outbox = new Outbox($this->outboxPath);

        foreach (range(1, $lineCount) as $lineNumber) {
            $outbox->append(json_encode(["n" => $lineNumber, "pad" => str_repeat("x", $lineBytes)]));
        }

        return $outbox;
    }

    /**
     * A shipper with the fake clock and a sleeper that advances it.
     */
    private function shipper(Outbox $outbox, Transport $transport, int $batchLines = 50, int $batchBytes = 262144): Shipper
    {
        return new Shipper(
            outbox: $outbox,
            transport: $transport,
            batchLines: $batchLines,
            batchBytes: $batchBytes,
            sleeper: function (int $seconds): void {
                $this->sleeps[] = $seconds;
                $this->now += max($seconds, 1);
            },
            clock: fn (): int => $this->now,
        );
    }

    /**
     * Every line still waiting, pending or claimed.
     */
    private function waitingLineCount(Outbox $outbox): int
    {
        $paths = array_merge(glob($this->outboxPath . "/pending/*.ndjson") ?: [], glob($this->outboxPath . "/sending/*.ndjson") ?: []);

        return array_sum(array_map(fn (string $path): int => count($outbox->lines($path)), $paths));
    }

    /**
     * The "n" of every line still waiting in a claimed file, in order.
     *
     * @return array<int, int>
     */
    private function waitingLineNumbers(Outbox $outbox): array
    {
        $lineNumbers = [];

        foreach (glob($this->outboxPath . "/sending/*.ndjson") ?: [] as $sendingPath) {
            foreach ($outbox->lines($sendingPath) as $line) {
                $lineNumbers[] = json_decode($line, true)["n"];
            }
        }

        return $lineNumbers;
    }

    /**
     * The "n" of every line a fake transport was sent, in order.
     *
     * @return array<int, int>
     */
    private function sentLineNumbers(FakeTransport $transport): array
    {
        $lineNumbers = [];

        foreach ($transport->sentBodies as $sentBody) {
            foreach (explode("\n", trim($sentBody)) as $line) {
                $lineNumbers[] = json_decode($line, true)["n"];
            }
        }

        return $lineNumbers;
    }

    /**
     * Writes one pending file of numbered lines straight to disk, as hours of logging without ingest would leave it.
     */
    private function writeLargePendingFile(int $lineCount, int $lineBytes): string
    {
        mkdir($this->outboxPath . "/pending", 0775, true);
        $pendingPath = $this->outboxPath . "/pending/backlog-1.ndjson";
        $pendingHandle = fopen($pendingPath, "wb");

        foreach (range(1, $lineCount) as $lineNumber) {
            fwrite($pendingHandle, json_encode(["n" => $lineNumber, "pad" => str_repeat("x", $lineBytes)]) . "\n");
        }

        fclose($pendingHandle);

        return $pendingPath;
    }

    public function test_sends_batches_within_the_line_and_byte_limits(): void
    {
        $outbox = $this->outboxWithLines(7, 100);
        $transport = new FakeTransport();

        $acknowledgedCount = $this->shipper($outbox, $transport, batchLines: 3, batchBytes: 300)->ship($this->now + 60);

        $this->assertSame(7, $acknowledgedCount);
        $this->assertSame([2, 2, 2, 1], $transport->linesPerBody());
        $this->assertSame(0, $this->waitingLineCount($outbox));
    }

    public function test_keeps_lines_and_backs_off_on_503_honouring_retry_after(): void
    {
        $outbox = $this->outboxWithLines(3);
        $transport = new FakeTransport([
            ["status" => 503, "retry_after" => 7],
            ["status" => 0, "retry_after" => null],
        ]);

        $acknowledgedCount = $this->shipper($outbox, $transport)->ship($this->now + 60);

        $this->assertSame(3, $acknowledgedCount);
        $this->assertSame(7, $this->sleeps[0]);
        $this->assertCount(3, $transport->sentBodies);
    }

    public function test_the_deadline_leaves_unsent_lines_in_the_outbox(): void
    {
        $outbox = $this->outboxWithLines(4);
        $transport = new FakeTransport(array_fill(0, 50, ["status" => 503, "retry_after" => 30]));

        $acknowledgedCount = $this->shipper($outbox, $transport)->ship($this->now + 60);

        $this->assertSame(0, $acknowledgedCount);
        $this->assertSame(4, $this->waitingLineCount($outbox));
    }

    public function test_a_short_retry_after_is_a_floor_and_the_backoff_still_grows(): void
    {
        $outbox = $this->outboxWithLines(2);
        $transport = new FakeTransport(array_fill(0, 30, ["status" => 429, "retry_after" => 1]));

        // A long deadline, since 30 waits under a 5-minute backoff ceiling can outlast an hour.
        $this->shipper($outbox, $transport)->ship($this->now + 100000);

        // Every wait honours Retry-After, and the jittered backoff grows past it instead of retrying every second.
        $this->assertGreaterThanOrEqual(30, count($this->sleeps));
        $this->assertGreaterThanOrEqual(1, min($this->sleeps));
        $this->assertGreaterThan(count($this->sleeps) * 2, array_sum($this->sleeps));
    }

    public function test_an_over_quota_hour_outlasts_the_run_and_pauses_later_runs(): void
    {
        $outbox = $this->outboxWithLines(3);
        $transport = new FakeTransport([["status" => 429, "retry_after" => 3600]]);

        $this->assertSame(0, $this->shipper($outbox, $transport)->ship($this->now + 55));
        $this->assertSame([], $this->sleeps);
        $this->assertTrue($outbox->isPaused($this->now + 3599));

        // The scheduled runs of the next hour send nothing.
        $this->now += 60;
        $this->shipper($outbox, $transport)->ship($this->now + 55);
        $this->assertCount(1, $transport->sentBodies);

        // Once the hour is over, everything goes.
        $this->now += 3600;
        $this->assertSame(3, $this->shipper($outbox, $transport)->ship($this->now + 55));
        $this->assertSame(0, $this->waitingLineCount($outbox));
    }

    public function test_random_answers_over_many_runs_lose_nothing_and_acknowledge_each_line_once(): void
    {
        foreach (range(1, 25) as $seed) {
            mt_srand($seed);
            $this->outboxPath = sys_get_temp_dir() . "/sdl-shipper-fuzz-" . bin2hex(random_bytes(6));
            $outbox = new Outbox($this->outboxPath);
            $transport = new RandomAnswerTransport();
            $appendedCount = 0;

            // Writers keep appending while runs meet random answers, with a minute between runs.
            foreach (range(1, 30) as $runNumber) {
                foreach (range(1, mt_rand(0, 40)) as $unused) {
                    $appendedCount += 1;
                    $outbox->append(json_encode(["n" => $appendedCount, "pad" => str_repeat("x", mt_rand(0, 300))]));
                }

                $this->shipper($outbox, $transport, batchLines: mt_rand(1, 50), batchBytes: mt_rand(400, 20000))->ship($this->now + 55);
                $this->now += 60 + mt_rand(0, 900);
            }

            // Then ingest accepts everything, and pauses run out.
            $transport->isAcceptingAll = true;
            $this->now += 3601;
            $this->shipper($outbox, $transport)->ship($this->now + 3600);

            $acceptedNumbers = $transport->lineNumbersAnswered([202]);
            // A lone line refused 413 and every line of a 415 batch are set aside, never resent.
            $setAsideNumbers = array_merge($transport->loneLineNumbersAnswered(413), $transport->lineNumbersAnswered([415]));

            $this->assertSame(count($acceptedNumbers), count(array_unique($acceptedNumbers)), "seed {$seed}: a line was accepted twice");
            $this->assertEqualsCanonicalizing(range(1, $appendedCount), array_merge($acceptedNumbers, $setAsideNumbers), "seed {$seed}: a line was lost");
            $this->assertSame(0, $this->waitingLineCount($outbox), "seed {$seed}");

            exec("rm -rf " . escapeshellarg($this->outboxPath));
        }
    }

    public function test_a_401_pauses_shipping_and_keeps_everything(): void
    {
        $outbox = $this->outboxWithLines(5);
        $transport = new FakeTransport([["status" => 401, "retry_after" => null]]);

        $acknowledgedCount = $this->shipper($outbox, $transport)->ship($this->now + 60);

        $this->assertSame(0, $acknowledgedCount);
        $this->assertCount(1, $transport->sentBodies);
        $this->assertTrue($outbox->isPaused($this->now));
        $this->assertSame(5, $this->waitingLineCount($outbox));

        // The next run during the pause sends nothing.
        $this->shipper($outbox, $transport)->ship($this->now + 60);
        $this->assertCount(1, $transport->sentBodies);
    }

    public function test_a_413_halves_the_batch_and_moves_a_lone_oversized_line_aside(): void
    {
        $outbox = $this->outboxWithLines(4);
        $transport = new FakeTransport([
            ["status" => 413, "retry_after" => null],
            ["status" => 202, "retry_after" => null],
            ["status" => 413, "retry_after" => null],
            ["status" => 413, "retry_after" => null],
        ]);

        $acknowledgedCount = $this->shipper($outbox, $transport, batchLines: 4)->ship($this->now + 60);

        // 4 refused → 2 sent → 2 refused → 1 refused (moved aside) → 1 sent.
        $this->assertSame([4, 2, 2, 1, 1], $transport->linesPerBody());
        $this->assertSame(3, $acknowledgedCount);
        $this->assertCount(1, $outbox->lines($this->outboxPath . "/rejected/rejected.ndjson"));
    }

    public function test_a_second_shipper_on_the_same_outbox_waits_its_turn(): void
    {
        $outbox = $this->outboxWithLines(2);
        $lockHandle = $outbox->lockShipper();
        $transport = new FakeTransport();

        $this->assertSame(0, $this->shipper($outbox, $transport)->ship($this->now + 60));
        $this->assertSame([], $transport->sentBodies);

        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }

    public function test_a_large_claimed_file_streams_in_many_batches_with_bounded_memory(): void
    {
        // About 10 MB in one file, far more than one batch.
        $lineCount = 50_000;
        $pendingPath = $this->writeLargePendingFile($lineCount, 180);
        $this->assertGreaterThan(9_000_000, filesize($pendingPath));

        // A transport that checks every line arrives once and in order, without keeping the bodies.
        $transport = new class () implements Transport {
            public int $lastLineNumber = 0;
            public int $outOfOrderCount = 0;
            public int $bodyCount = 0;
            public int $largestBodyBytes = 0;

            public function send(string $ndjsonBody): array
            {
                $this->bodyCount++;
                $this->largestBodyBytes = max($this->largestBodyBytes, strlen($ndjsonBody));

                foreach (explode("\n", trim($ndjsonBody)) as $line) {
                    $lineNumber = json_decode($line, true)["n"];
                    $this->outOfOrderCount += $lineNumber === $this->lastLineNumber + 1 ? 0 : 1;
                    $this->lastLineNumber = $lineNumber;
                }

                return ["status" => 202, "retry_after" => null];
            }
        };

        // Measure only the shipping run's own peak.
        $outbox = new Outbox($this->outboxPath);
        $shipper = new Shipper(outbox: $outbox, transport: $transport, batchLines: 5000, batchBytes: 262144, clock: fn (): int => $this->now);
        memory_reset_peak_usage();
        $baselineBytes = memory_get_usage();

        $acknowledgedCount = $shipper->ship($this->now + 60);

        $peakGrowthBytes = memory_get_peak_usage() - $baselineBytes;

        // Every line once, in order, in byte-capped batches, and nothing left behind.
        $this->assertSame($lineCount, $acknowledgedCount);
        $this->assertSame($lineCount, $transport->lastLineNumber);
        $this->assertSame(0, $transport->outOfOrderCount);
        $this->assertGreaterThan(30, $transport->bodyCount);
        $this->assertLessThanOrEqual(262144, $transport->largestBodyBytes);
        $this->assertSame([], glob($this->outboxPath . "/sending/*"));

        // Roughly one batch of overhead, not the 10 MB file.
        $this->assertLessThan(2 * 1024 * 1024, $peakGrowthBytes, "peak memory grew by {$peakGrowthBytes} bytes");
    }

    public function test_a_failure_mid_file_keeps_exactly_the_unshipped_lines(): void
    {
        $outbox = $this->outboxWithLines(10);
        $transport = new FakeTransport(array_merge(
            array_fill(0, 2, ["status" => 202, "retry_after" => null]),
            array_fill(0, 50, ["status" => 503, "retry_after" => 30]),
        ));

        $acknowledgedCount = $this->shipper($outbox, $transport, batchLines: 3)->ship($this->now + 60);

        $this->assertSame(6, $acknowledgedCount);
        $this->assertSame([7, 8, 9, 10], $this->waitingLineNumbers($outbox));

        // Only the offset moved; the claimed file itself was never rewritten.
        [$sendingPath] = glob($this->outboxPath . "/sending/*.ndjson");
        $this->assertCount(10, file($sendingPath));
    }

    public function test_a_restart_after_a_crash_mid_file_resends_only_the_batch_in_flight(): void
    {
        $outbox = $this->outboxWithLines(10);
        $crashingTransport = new FakeTransport([
            ["status" => 202, "retry_after" => null],
            ["status" => 202, "retry_after" => null],
            new \RuntimeException("the process died mid-request"),
        ]);

        // The first run dies while the third batch is in flight.
        try {
            $this->shipper($outbox, $crashingTransport, batchLines: 3)->ship($this->now + 60);
            $this->fail("the crash should have escaped the shipper");
        } catch (\RuntimeException) {
            $this->assertSame(range(1, 9), $this->sentLineNumbers($crashingTransport));
        }

        // A fresh shipper picks up at the first unacknowledged line.
        $restartTransport = new FakeTransport();
        $acknowledgedCount = $this->shipper($outbox, $restartTransport, batchLines: 3)->ship($this->now + 60);

        $this->assertSame(4, $acknowledgedCount);
        $this->assertSame([7, 8, 9, 10], $this->sentLineNumbers($restartTransport));
        $this->assertSame([], glob($this->outboxPath . "/sending/*"));
    }

    public function test_a_leftover_offset_without_its_file_is_swept_on_claim(): void
    {
        // A crash between deleting a finished file and deleting its offset.
        $outbox = new Outbox($this->outboxPath);
        mkdir($this->outboxPath . "/sending", 0775, true);
        file_put_contents($this->outboxPath . "/sending/1800000000.000000-dead.ndjson.offset", "4096");

        $this->assertSame([], $outbox->claim());
        $this->assertSame([], glob($this->outboxPath . "/sending/*"));
    }
}
