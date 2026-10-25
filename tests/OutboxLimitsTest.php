<?php

namespace SimpleDataLogs\Laravel\Tests;

use SimpleDataLogs\Laravel\LineBuilder;
use SimpleDataLogs\Laravel\Outbox;
use SimpleDataLogs\Laravel\Shipper;
use SimpleDataLogs\Laravel\Transport;

use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase as PlainTestCase;

/**
 * The outbox's bounds: total and per-file caps, the line cap, rejected rotation, no claims while paused, batch regrowth and 415.
 */
class OutboxLimitsTest extends PlainTestCase
{
    // The outbox folder of the running test.
    private string $outboxPath;

    // Where error_log writes during the test.
    private string $errorLogPath;

    // The PHP error_log setting before the test.
    private string|false $previousErrorLog;

    // The fake clock, in seconds.
    private int $now = 1_800_000_000;

    /**
     * Gives every test a fresh outbox and a readable error log.
     */
    protected function setUp(): void
    {
        $this->outboxPath = sys_get_temp_dir() . "/sdl-limits-" . bin2hex(random_bytes(6));
        $this->errorLogPath = $this->outboxPath . "-errors.log";
        $this->previousErrorLog = ini_get("error_log");
        ini_set("error_log", $this->errorLogPath);
    }

    /**
     * Removes the test's files and restores the error log.
     */
    protected function tearDown(): void
    {
        ini_set("error_log", $this->previousErrorLog === false ? "" : $this->previousErrorLog);
        exec("rm -rf " . escapeshellarg($this->outboxPath) . " " . escapeshellarg($this->errorLogPath));
    }

    /**
     * Points error_log at the test's file from inside the test body, where PHPUnit 12 sets its own capture around each test.
     */
    private function captureErrorLog(): void
    {
        ini_set("error_log", $this->errorLogPath);
    }

    /**
     * A shipper on the fake clock whose sleeps advance it.
     */
    private function shipper(Outbox $outbox, Transport $transport, int $batchLines = 50): Shipper
    {
        return new Shipper(
            outbox: $outbox,
            transport: $transport,
            batchLines: $batchLines,
            sleeper: function (int $seconds): void {
                $this->now += max($seconds, 1);
            },
            clock: fn (): int => $this->now,
        );
    }

    /**
     * The lines the error log holds that mention simpledatalogs.
     *
     * @return array<int, string>
     */
    private function loggedErrors(): array
    {
        $loggedText = @file_get_contents($this->errorLogPath) ?: "";

        return array_values(array_filter(explode("\n", $loggedText), fn (string $line): bool => str_contains($line, "simpledatalogs")));
    }

    /**
     * The "n" of every line waiting in pending or claimed files.
     *
     * @return array<int, int>
     */
    private function waitingLineNumbers(Outbox $outbox): array
    {
        $waitingPaths = array_merge(glob($this->outboxPath . "/pending/*.ndjson") ?: [], glob($this->outboxPath . "/sending/*.ndjson") ?: []);
        $lineNumbers = [];

        foreach ($waitingPaths as $waitingPath) {
            foreach ($outbox->lines($waitingPath) as $line) {
                $lineNumbers[] = json_decode($line, true)["n"];
            }
        }

        return $lineNumbers;
    }

    public function test_an_outage_never_grows_the_outbox_past_its_cap_and_the_oldest_unsent_lines_go(): void
    {
        $this->captureErrorLog();
        $outbox = new Outbox($this->outboxPath, maxOutboxBytes: 1_000_000, maxFileBytes: 200_000);
        $transport = new FakeTransport(array_fill(0, 10_000, ["status" => 503, "retry_after" => null]));

        // Five runs, each after 4,000 more lines of about 200 bytes, with ingest down throughout.
        foreach (range(0, 4) as $runIndex) {
            foreach (range(1, 4000) as $lineIndex) {
                $outbox->append(json_encode(["n" => $runIndex * 4000 + $lineIndex, "pad" => str_repeat("x", 180)]));
            }

            $this->shipper($outbox, $transport)->ship($this->now + 55);
            $this->now += 60;
        }

        $waitingNumbers = $this->waitingLineNumbers($outbox);

        // One file over the cap at most, plus the lines written between two size checks.
        $this->assertLessThanOrEqual(1_000_000 + 200_000 + 1000 * 210, $outbox->waitingBytes());
        $this->assertContains(20_000, $waitingNumbers);
        $this->assertNotContains(1, $waitingNumbers);
        $this->assertNotEmpty($this->loggedErrors());
        $this->assertCount(1, $this->loggedErrors());
    }

    public function test_a_pending_file_past_its_size_moves_to_sending_and_a_new_one_starts(): void
    {
        $outbox = new Outbox($this->outboxPath, maxFileBytes: 10_000);

        foreach (range(1, 100) as $lineNumber) {
            $outbox->append(json_encode(["n" => $lineNumber, "pad" => str_repeat("x", 200)]));
        }

        $sendingPaths = glob($this->outboxPath . "/sending/*.ndjson") ?: [];

        $this->assertGreaterThanOrEqual(2, count($sendingPaths));
        $this->assertTrue(array_reduce($sendingPaths, fn (bool $isSmall, string $path): bool => $isSmall && filesize($path) < 11_000, true));
        $this->assertEqualsCanonicalizing(range(1, 100), $this->waitingLineNumbers($outbox));
    }

    public function test_a_line_with_a_huge_context_is_rebuilt_small_valid_and_marked_truncated(): void
    {
        $lineBuilder = new LineBuilder(appEnvironment: "testing");

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: "partner_api",
            level: Level::Error,
            message: str_repeat("m", 50_000),
            context: ["response" => str_repeat("r", 1_000_000)],
        );

        $line = $lineBuilder->build($record);
        $decodedLine = json_decode($line, true);

        $this->assertLessThan(262_144, strlen($line));
        $this->assertTrue($decodedLine["props"]["_truncated"]);
        $this->assertGreaterThan(1_000_000, $decodedLine["props"]["_dropped_bytes"]);
        $this->assertSame("partner_api", $decodedLine["props"]["channel"]);
        $this->assertSame(8192, strlen($decodedLine["message"]));
        $this->assertArrayHasKey("event_id", $decodedLine);
    }

    public function test_the_rejected_file_rotates_and_keeps_only_the_newest_two(): void
    {
        $outbox = new Outbox($this->outboxPath);

        foreach (range(1, 3) as $lineNumber) {
            $outbox->reject(json_encode(["n" => $lineNumber, "pad" => str_repeat("x", 10_000_000)]));
        }

        $this->assertLessThanOrEqual(2, count(glob($this->outboxPath . "/rejected/*.ndjson") ?: []));
        $this->assertStringContainsString("\"n\":3", file_get_contents($this->outboxPath . "/rejected/rejected.ndjson"));
    }

    public function test_while_paused_runs_claim_nothing_so_claimed_files_never_pile_up(): void
    {
        $outbox = new Outbox($this->outboxPath);
        $transport = new FakeTransport(array_fill(0, 100, ["status" => 503, "retry_after" => 3600]));

        foreach (range(1, 100) as $runNumber) {
            $outbox->append(json_encode(["n" => $runNumber]));
            $this->shipper($outbox, $transport)->ship($this->now + 55);
            $this->now += 30;
        }

        $this->assertLessThanOrEqual(1, count(glob($this->outboxPath . "/sending/*.ndjson") ?: []));
        $this->assertCount(100, $this->waitingLineNumbers($outbox));
    }

    public function test_past_200_claimed_files_pending_files_stay_where_they_are(): void
    {
        $outbox = new Outbox($this->outboxPath);
        mkdir($this->outboxPath . "/sending", 0775, true);

        foreach (range(1, 200) as $fileNumber) {
            file_put_contents($this->outboxPath . "/sending/" . sprintf("1.%06d", $fileNumber) . "-old.ndjson", json_encode(["n" => $fileNumber]) . "\n");
        }

        $outbox->append(json_encode(["n" => 999]));
        $outbox->claim();

        $this->assertCount(1, glob($this->outboxPath . "/pending/*.ndjson") ?: []);
        $this->assertCount(200, glob($this->outboxPath . "/sending/*.ndjson") ?: []);
    }

    public function test_after_a_413_the_batch_grows_back_on_each_202(): void
    {
        $outbox = new Outbox($this->outboxPath);

        foreach (range(1, 60) as $lineNumber) {
            $outbox->append(json_encode(["n" => $lineNumber]));
        }

        $transport = new FakeTransport([["status" => 413, "retry_after" => null]]);
        $this->shipper($outbox, $transport, batchLines: 8)->ship($this->now + 55);

        $this->assertSame([8, 4, 8, 8], array_slice($transport->linesPerBody(), 0, 4));
    }

    public function test_a_415_moves_the_batch_aside_logs_once_and_sends_the_next(): void
    {
        $this->captureErrorLog();
        $outbox = new Outbox($this->outboxPath);

        foreach (range(1, 20) as $lineNumber) {
            $outbox->append(json_encode(["n" => $lineNumber]));
        }

        $transport = new FakeTransport([["status" => 415, "retry_after" => null], ["status" => 415, "retry_after" => null]]);

        $this->assertSame(10, $this->shipper($outbox, $transport, batchLines: 5)->ship($this->now + 55));

        $rejectedLines = array_filter(explode("\n", file_get_contents($this->outboxPath . "/rejected/rejected.ndjson")));
        $this->assertCount(10, $rejectedLines);
        $this->assertCount(1, $this->loggedErrors());
        $this->assertSame([], $this->waitingLineNumbers($outbox));
    }

    public function test_waits_have_a_one_second_floor_and_a_long_retry_after_is_capped_at_an_hour(): void
    {
        $outbox = new Outbox($this->outboxPath);
        $outbox->append(json_encode(["n" => 1]));

        $transport = new FakeTransport([["status" => 503, "retry_after" => 86_400]]);
        $this->shipper($outbox, $transport)->ship($this->now + 55);

        // The day-long Retry-After became a pause of one hour, not a day.
        $this->assertTrue($outbox->isPaused($this->now + 3599));
        $this->assertFalse($outbox->isPaused($this->now + 3601));
    }
}
