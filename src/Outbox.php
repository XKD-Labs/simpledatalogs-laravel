<?php

namespace SimpleDataLogs\Laravel;

/**
 * The disk outbox: each process appends lines to its own pending file; the shipper claims files and acks lines only after a 202.
 */
class Outbox
{
    // Appends between two checks of the outbox's total size; summing file sizes is cheap but not free.
    private const SIZE_CHECK_EVERY_APPENDS = 1000;

    // Claimed files claim() lets pile up before it stops moving pending files in.
    private const MAX_SENDING_FILES = 200;

    // The rejected file rotates at this size, and this many rejected files are kept.
    private const MAX_REJECTED_FILE_BYTES = 16777216;
    private const KEPT_REJECTED_FILES = 2;

    // Per outbox folder, appends since this process last summed it and whether that sum was over the cap.
    /** @var array<string, array{appends: int, is_over_cap: bool}> */
    private static array $sizeChecks = [];

    // Outbox folders this process already warned about dropping files from.
    /** @var array<string, true> */
    private static array $hasWarnedDropping = [];

    /**
     * Points the outbox at its folder with its caps (512 MB in all, 64 MB per file); nothing is created until the first line.
     */
    public function __construct(
        private string $outboxPath,
        private int $maxOutboxBytes = 536870912,
        private int $maxFileBytes = 67108864,
    ) {
    }

    /**
     * Appends one line to this process's pending file, under an exclusive lock, never into a file the shipper already claimed.
     */
    public function append(string $line): void
    {
        // Past the cap the host's disk comes first: the oldest unsent files go, and only when none can go is the new line skipped (only acknowledged lines are promised).
        if ($this->isOverCapNow()) {
            return;
        }

        $pendingFolder = $this->folder("pending");
        $pendingPath = $pendingFolder . "/" . gethostname() . "-" . getmypid() . ".ndjson";

        // A claim can rename the file between open and lock; then the write goes to a fresh pending file instead.
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $pendingHandle = fopen($pendingPath, "ab");

            if ($pendingHandle === false) {
                throw new \RuntimeException("simpledatalogs outbox is not writable: {$pendingPath}");
            }

            try {
                $this->afterOpen($pendingPath);
                flock($pendingHandle, LOCK_EX);

                if ($this->isStillPending($pendingHandle, $pendingPath)) {
                    fwrite($pendingHandle, str_replace("\n", " ", $line) . "\n");
                    fflush($pendingHandle);

                    // A full file moves to sending under the lock, so the next line starts a fresh one.
                    if (fstat($pendingHandle)["size"] > $this->maxFileBytes) {
                        @rename($pendingPath, $this->newSendingPath());
                    }

                    flock($pendingHandle, LOCK_UN);

                    return;
                }

                flock($pendingHandle, LOCK_UN);
            } finally {
                fclose($pendingHandle);
            }
        }

        throw new \RuntimeException("simpledatalogs outbox kept moving under the writer: {$pendingPath}");
    }

    /**
     * True while the outbox is over its cap, summing its files once every 1,000 appends of this process.
     */
    private function isOverCapNow(): bool
    {
        $sizeCheck = self::$sizeChecks[$this->outboxPath] ?? ["appends" => 0, "is_over_cap" => false];
        $sizeCheck["appends"]++;

        // The first append of a process sums at once; after that, every 1,000th.
        if ($sizeCheck["appends"] === 1 || $sizeCheck["appends"] > self::SIZE_CHECK_EVERY_APPENDS) {
            $wasOverCap = $sizeCheck["is_over_cap"];

            // Over the cap, the oldest claimed files make room first; only an outbox with none left skips new lines.
            if ($this->waitingBytes() > $this->maxOutboxBytes) {
                $this->dropOldestOverCap($this->sendingPaths());
            }

            $sizeCheck = ["appends" => 1, "is_over_cap" => $this->waitingBytes() > $this->maxOutboxBytes];

            if ($sizeCheck["is_over_cap"] && ! $wasOverCap) {
                error_log("simpledatalogs: outbox over its {$this->maxOutboxBytes}-byte cap with nothing claimed to drop; skipping new lines until the shipper catches up");
            }
        }

        self::$sizeChecks[$this->outboxPath] = $sizeCheck;

        return $sizeCheck["is_over_cap"];
    }

    /**
     * Bytes waiting in pending and claimed files.
     */
    public function waitingBytes(): int
    {
        $waitingPaths = array_merge(glob($this->folder("pending") . "/*.ndjson") ?: [], glob($this->folder("sending") . "/*.ndjson") ?: []);

        return array_sum(array_map(fn (string $path): int => (int) @filesize($path), $waitingPaths));
    }

    /**
     * Every claimed file, oldest first.
     *
     * @return array<int, string>
     */
    private function sendingPaths(): array
    {
        $sendingPaths = glob($this->folder("sending") . "/*.ndjson") ?: [];
        sort($sendingPaths);

        return $sendingPaths;
    }

    /**
     * A fresh claimed-file path that sorts after every earlier one.
     */
    private function newSendingPath(): string
    {
        return $this->folder("sending") . "/" . sprintf("%.6f", microtime(true)) . "-" . bin2hex(random_bytes(4)) . ".ndjson";
    }

    /**
     * A seam for tests, between opening the pending file and locking it.
     */
    protected function afterOpen(string $pendingPath): void
    {
    }

    /**
     * True when the open handle is still the file at the pending path, not one a claim renamed away.
     *
     * @param resource $pendingHandle
     */
    private function isStillPending(mixed $pendingHandle, string $pendingPath): bool
    {
        clearstatcache(true, $pendingPath);
        $openFile = fstat($pendingHandle);
        $pathFile = @stat($pendingPath);

        return $pathFile !== false && $openFile["ino"] === $pathFile["ino"] && $openFile["dev"] === $pathFile["dev"];
    }

    /**
     * Moves every pending file into sending, then lists every sending file, oldest first, leftovers included.
     *
     * @return array<int, string>
     */
    public function claim(): array
    {
        $sendingFolder = $this->folder("sending");

        // While many claimed files wait (an outage), pending files grow in place instead of multiplying.
        if (count(glob($sendingFolder . "/*.ndjson") ?: []) < self::MAX_SENDING_FILES) {
            foreach (glob($this->folder("pending") . "/*.ndjson") ?: [] as $pendingPath) {
                // A rename is atomic, so a line is either still pending or already claimed.
                $claimedPath = $this->newSendingPath();

                if (@rename($pendingPath, $claimedPath)) {
                    $this->waitForWriters($claimedPath);
                }
            }
        }

        // Oldest first, so lines leave in the order they were written.
        $sendingPaths = glob($sendingFolder . "/*.ndjson") ?: [];
        sort($sendingPaths);

        // Sweep progress files whose claimed file is already gone.
        $this->removeOrphanSidecars($sendingFolder);

        return $this->dropOldestOverCap($sendingPaths);
    }

    /**
     * Deletes the oldest claimed files while the outbox is over its cap, with one warning; returns the files left.
     *
     * @param array<int, string> $sendingPaths
     * @return array<int, string>
     */
    private function dropOldestOverCap(array $sendingPaths): array
    {
        $waitingBytes = $this->waitingBytes();
        $droppedCount = 0;

        // These lines never got a 202, so dropping them breaks no promise; the host's disk comes first.
        while ($waitingBytes > $this->maxOutboxBytes && $sendingPaths !== []) {
            $oldestPath = array_shift($sendingPaths);
            $waitingBytes -= (int) @filesize($oldestPath);
            $this->finish($oldestPath);
            $droppedCount++;
        }

        // One warning per process and outbox, so a long outage doesn't flood the error log.
        if ($droppedCount > 0 && ! isset(self::$hasWarnedDropping[$this->outboxPath])) {
            self::$hasWarnedDropping[$this->outboxPath] = true;
            error_log("simpledatalogs: outbox over its {$this->maxOutboxBytes}-byte cap; dropped the {$droppedCount} oldest unsent files");
        }

        return $sendingPaths;
    }

    /**
     * Deletes progress files left by a crash between finishing a claimed file and deleting its offset.
     */
    private function removeOrphanSidecars(string $sendingFolder): void
    {
        foreach (glob($sendingFolder . "/*.ndjson.offset*") ?: [] as $sidecarPath) {
            // The claimed file's path is the sidecar's up to and including ".ndjson".
            $claimedPath = substr($sidecarPath, 0, strpos($sidecarPath, ".ndjson.offset") + strlen(".ndjson"));

            if (! file_exists($claimedPath)) {
                @unlink($sidecarPath);
            }
        }
    }

    /**
     * Takes and drops the claimed file's lock, so a writer that locked it before the rename finishes its line before the shipper reads.
     */
    private function waitForWriters(string $claimedPath): void
    {
        $claimedHandle = @fopen($claimedPath, "rb");

        if ($claimedHandle === false) {
            return;
        }

        flock($claimedHandle, LOCK_EX);
        flock($claimedHandle, LOCK_UN);
        fclose($claimedHandle);
    }

    /**
     * The lines of one claimed file not yet acknowledged, in order; whole-file, so for inspection and tests (the shipper streams).
     *
     * @return array<int, string>
     */
    public function lines(string $sendingPath): array
    {
        $fileText = @file_get_contents($sendingPath, false, null, $this->acknowledgedOffset($sendingPath));

        if ($fileText === false || $fileText === "") {
            return [];
        }

        return array_values(array_filter(explode("\n", $fileText), fn (string $line): bool => $line !== ""));
    }

    /**
     * Opens a claimed file for streaming reads; null when it is gone.
     *
     * @return resource|null
     */
    public function open(string $sendingPath): mixed
    {
        $fileHandle = @fopen($sendingPath, "rb");

        return $fileHandle === false ? null : $fileHandle;
    }

    /**
     * The byte offset where a claimed file's unacknowledged lines start; 0 when nothing was acknowledged yet.
     */
    public function acknowledgedOffset(string $sendingPath): int
    {
        $offsetText = @file_get_contents($this->sidecarPath($sendingPath));

        // An unreadable offset re-sends from the start: duplicates (deduped by event_id) are better than a gap.
        return $offsetText !== false && ctype_digit($offsetText) ? (int) $offsetText : 0;
    }

    /**
     * Records that every line before the byte offset was acknowledged, without touching the claimed file itself.
     */
    public function acknowledgeUpTo(string $sendingPath, int $byteOffset): void
    {
        // Write a sibling, then rename it over, so a crash leaves the old offset or the new one, never half of one.
        $sidecarPath = $this->sidecarPath($sendingPath);
        file_put_contents($sidecarPath . ".tmp", (string) $byteOffset);
        rename($sidecarPath . ".tmp", $sidecarPath);
    }

    /**
     * Deletes a fully acknowledged claimed file, then its offset; a crash in between only leaves a sidecar for claim() to sweep.
     */
    public function finish(string $sendingPath): void
    {
        @unlink($sendingPath);
        @unlink($this->sidecarPath($sendingPath));
    }

    /**
     * Keeps only the lines not yet acknowledged; the file goes once nothing is left.
     *
     * @param array<int, string> $remainingLines
     */
    public function keep(string $sendingPath, array $remainingLines): void
    {
        if ($remainingLines === []) {
            $this->finish($sendingPath);

            return;
        }

        // The rewritten file starts at its first unacknowledged line, so the old offset goes first (a crash re-sends, never skips).
        @unlink($this->sidecarPath($sendingPath));

        // Write a sibling, then rename it over, so a crash never leaves a half file.
        $temporaryPath = $sendingPath . ".tmp";
        file_put_contents($temporaryPath, implode("\n", $remainingLines) . "\n");
        rename($temporaryPath, $sendingPath);
    }

    /**
     * The progress file beside a claimed file; its name never matches the claimed-file glob.
     */
    private function sidecarPath(string $sendingPath): string
    {
        return $sendingPath . ".offset";
    }

    /**
     * Moves a line ingest can never accept (over the body limit on its own) aside, never deleting it.
     */
    public function reject(string $line): void
    {
        $rejectedFolder = $this->folder("rejected");
        $rejectedPath = $rejectedFolder . "/rejected.ndjson";

        // Past 16 MB the file rotates, and only the newest two are kept.
        clearstatcache(true, $rejectedPath);

        if ((int) @filesize($rejectedPath) > self::MAX_REJECTED_FILE_BYTES) {
            @rename($rejectedPath, $rejectedFolder . "/rejected-" . sprintf("%.6f", microtime(true)) . ".ndjson");

            $rotatedPaths = glob($rejectedFolder . "/rejected-*.ndjson") ?: [];
            sort($rotatedPaths);

            foreach (array_slice($rotatedPaths, 0, max(count($rotatedPaths) - (self::KEPT_REJECTED_FILES - 1), 0)) as $oldRejectedPath) {
                @unlink($oldRejectedPath);
            }
        }

        file_put_contents($rejectedPath, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Takes the shipper lock without waiting; the handle must stay open while shipping.
     *
     * @return resource|null
     */
    public function lockShipper(): mixed
    {
        $lockHandle = fopen($this->folder("") . "/ship.lock", "c");

        if ($lockHandle === false || ! flock($lockHandle, LOCK_EX | LOCK_NB)) {
            return null;
        }

        return $lockHandle;
    }

    /**
     * Pauses shipping until a time: after a 401 (ingest API: keep the outbox, re-probe slowly), or a Retry-After longer than the run.
     */
    public function pauseUntil(int $unixSeconds): void
    {
        file_put_contents($this->folder("") . "/paused-until", (string) $unixSeconds);
    }

    /**
     * True while a pause lasts.
     */
    public function isPaused(int $nowUnixSeconds): bool
    {
        $pausedUntil = @file_get_contents($this->outboxPath . "/paused-until");

        return $pausedUntil !== false && (int) $pausedUntil > $nowUnixSeconds;
    }

    /**
     * A subfolder of the outbox, created on first use.
     */
    private function folder(string $subfolder): string
    {
        $folderPath = rtrim($this->outboxPath . "/" . $subfolder, "/");

        if (! is_dir($folderPath) && ! @mkdir($folderPath, 0775, true) && ! is_dir($folderPath)) {
            throw new \RuntimeException("simpledatalogs outbox folder can't be created: {$folderPath}");
        }

        return $folderPath;
    }
}
