<?php

namespace SimpleDataLogs\Laravel;

/**
 * Streams the outbox in batches (the sender rules): ack only on 202, split on 413, pause on 401, back off with jitter otherwise.
 */
class Shipper
{
    // A 401 pauses shipping this long before the next probe (ingest API: re-probe every ~10 minutes).
    private const INVALID_KEY_PAUSE_SECONDS = 600;

    // Backoff bounds for retryable answers, in seconds (ingest API: up to 5 minutes).
    private const FIRST_BACKOFF_SECONDS = 1;
    private const MAX_BACKOFF_SECONDS = 300;

    // The longest Retry-After honoured in one wait; a longer one is capped here.
    private const MAX_RETRY_AFTER_SECONDS = 3600;

    // Lines acknowledged so far in the current run.
    private int $acknowledgedCount = 0;

    // The current run's backoff ceiling for the next retryable answer, in seconds.
    private int $backoffSeconds = self::FIRST_BACKOFF_SECONDS;

    // The configured lines per batch; a 413 halves the working value, each 202 doubles it back up to this.
    private int $configuredBatchLines;

    // Whether this run already logged a 415.
    private bool $hasLoggedUnsupported = false;

    /**
     * Keeps the outbox, the transport and the batch limits; sleeper and clock are swappable for tests.
     *
     * @param \Closure(int): void|null $sleeper
     * @param \Closure(): int|null $clock
     */
    public function __construct(
        private Outbox $outbox,
        private Transport $transport,
        private int $batchLines = 50,
        private int $batchBytes = 262144,
        private ?\Closure $sleeper = null,
        private ?\Closure $clock = null,
    ) {
        $this->sleeper ??= fn (int $seconds) => sleep($seconds);
        $this->clock ??= fn (): int => time();
        $this->configuredBatchLines = $batchLines;
    }

    /**
     * Ships until the outbox is empty or the deadline passes; returns how many lines were acknowledged.
     */
    public function ship(int $deadlineUnixSeconds): int
    {
        // One shipper at a time per outbox; a second run just leaves.
        $lockHandle = $this->outbox->lockShipper();

        if ($lockHandle === null) {
            return 0;
        }

        try {
            return $this->shipClaimedFiles($deadlineUnixSeconds);
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    /**
     * Sends every claimed file in turn, oldest first, until one stops the run.
     */
    private function shipClaimedFiles(int $deadlineUnixSeconds): int
    {
        $this->acknowledgedCount = 0;
        $this->backoffSeconds = self::FIRST_BACKOFF_SECONDS;
        $this->batchLines = $this->configuredBatchLines;
        $this->hasLoggedUnsupported = false;

        // During a pause nothing is claimed, so an outage never piles up claimed files.
        if ($this->outbox->isPaused(($this->clock)())) {
            return 0;
        }

        foreach ($this->outbox->claim() as $sendingPath) {
            if (! $this->shipClaimedFile($sendingPath, $deadlineUnixSeconds)) {
                break;
            }
        }

        return $this->acknowledgedCount;
    }

    /**
     * Streams one claimed file in batches; returns false when a pause or the deadline stops the run first.
     * Progress is the byte offset after the last 202, kept in a sidecar, so the file is never rewritten and a crash re-sends only the batch in flight.
     */
    private function shipClaimedFile(string $sendingPath, int $deadlineUnixSeconds): bool
    {
        $fileHandle = $this->outbox->open($sendingPath);

        // Gone already: nothing to send.
        if ($fileHandle === null) {
            return true;
        }

        try {
            $acknowledgedOffset = $this->outbox->acknowledgedOffset($sendingPath);

            while (true) {
                // A 401 pause or the deadline stops this run; the offset already marks where the next one starts.
                if ($this->outbox->isPaused(($this->clock)()) || ($this->clock)() >= $deadlineUnixSeconds) {
                    return false;
                }

                // Read the next batch from the first unacknowledged line, so a retry re-reads the same lines.
                fseek($fileHandle, $acknowledgedOffset);
                ["lines" => $batchLines, "end_offset" => $batchEndOffset] = $this->readBatch($fileHandle);

                if ($batchLines === []) {
                    break;
                }

                $answer = $this->transport->send(implode("\n", $batchLines) . "\n");

                if ($answer["status"] === 202) {
                    // Acknowledged: move the offset past exactly these lines, and reset the backoff.
                    $acknowledgedOffset = $batchEndOffset;
                    $this->outbox->acknowledgeUpTo($sendingPath, $acknowledgedOffset);
                    $this->acknowledgedCount += count($batchLines);
                    $this->backoffSeconds = self::FIRST_BACKOFF_SECONDS;
                    $this->batchLines = min($this->batchLines * 2, $this->configuredBatchLines);
                } elseif ($answer["status"] === 415) {
                    // Ingest can never take this batch as it is (ingest API: never resend unchanged); it moves aside and the next batch goes.
                    $this->rejectUnsupported($batchLines);
                    $acknowledgedOffset = $batchEndOffset;
                    $this->outbox->acknowledgeUpTo($sendingPath, $acknowledgedOffset);
                } elseif ($answer["status"] === 413) {
                    // Too large: halve the batch; a single line too large on its own is moved aside and skipped.
                    if ($this->splitOrReject($batchLines)) {
                        $acknowledgedOffset = $batchEndOffset;
                        $this->outbox->acknowledgeUpTo($sendingPath, $acknowledgedOffset);
                    }
                } elseif ($answer["status"] === 401) {
                    // A bad or revoked key: keep everything and probe again later.
                    $this->outbox->pauseUntil(($this->clock)() + self::INVALID_KEY_PAUSE_SECONDS);
                } else {
                    // 429, 503, a network error or anything else: full-jitter backoff, never shorter than Retry-After.
                    $jitterSeconds = max(1, random_int(0, $this->backoffSeconds));
                    $waitSeconds = max($jitterSeconds, min($answer["retry_after"] ?? 0, self::MAX_RETRY_AFTER_SECONDS));
                    $this->backoffSeconds = min($this->backoffSeconds * 2, self::MAX_BACKOFF_SECONDS);

                    // A wait past this run's deadline (over quota asks an hour) is kept on disk, so later runs wait too.
                    if ($waitSeconds > $deadlineUnixSeconds - ($this->clock)()) {
                        $this->outbox->pauseUntil(($this->clock)() + $waitSeconds);
                    } else {
                        ($this->sleeper)($waitSeconds);
                    }
                }
            }
        } finally {
            fclose($fileHandle);
        }

        // Every line was acknowledged or moved aside: the file and its offset go.
        $this->outbox->finish($sendingPath);

        return true;
    }

    /**
     * Reads the next lines from the handle's position: at most the line limit, within the byte limit (at least one line).
     *
     * @param resource $fileHandle
     * @return array{lines: array<int, string>, end_offset: int}
     */
    private function readBatch(mixed $fileHandle): array
    {
        $batchLines = [];
        $batchByteCount = 0;
        $batchEndOffset = ftell($fileHandle);

        while (count($batchLines) < $this->batchLines) {
            $rawLine = fgets($fileHandle);

            if ($rawLine === false) {
                break;
            }

            $line = rtrim($rawLine, "\n");

            // A blank line carries nothing: step past it.
            if ($line === "") {
                $batchEndOffset = ftell($fileHandle);

                continue;
            }

            // A line that would overflow the byte limit starts the next batch instead.
            $lineByteCount = strlen($line) + 1;

            if ($batchLines !== [] && $batchByteCount + $lineByteCount > $this->batchBytes) {
                break;
            }

            $batchLines[] = $line;
            $batchByteCount += $lineByteCount;
            $batchEndOffset = ftell($fileHandle);
        }

        return ["lines" => $batchLines, "end_offset" => $batchEndOffset];
    }

    /**
     * Moves a batch ingest answered 415 into the rejected file, logging once per run.
     *
     * @param array<int, string> $refusedBatch
     */
    private function rejectUnsupported(array $refusedBatch): void
    {
        if (! $this->hasLoggedUnsupported) {
            error_log("simpledatalogs: ingest refused a batch as unsupported (415); moved " . count($refusedBatch) . " lines aside");
            $this->hasLoggedUnsupported = true;
        }

        foreach ($refusedBatch as $refusedLine) {
            $this->outbox->reject($refusedLine);
        }
    }

    /**
     * After a 413: send half next time, or move a lone oversized line aside; true when the batch's lines are done with.
     *
     * @param array<int, string> $refusedBatch
     */
    private function splitOrReject(array $refusedBatch): bool
    {
        if (count($refusedBatch) === 1) {
            $this->outbox->reject($refusedBatch[0]);

            return true;
        }

        $this->batchLines = max(intdiv(count($refusedBatch), 2), 1);

        return false;
    }
}
