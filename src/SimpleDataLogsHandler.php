<?php

namespace SimpleDataLogs\Laravel;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * A Monolog handler that only appends to the disk outbox: no network in the request, and it never throws into the app.
 */
class SimpleDataLogsHandler extends AbstractProcessingHandler
{
    /**
     * Keeps the line builder, the outbox and the channel name lines carry; records below the level are skipped.
     */
    public function __construct(
        private LineBuilder $lineBuilder,
        private Outbox $outbox,
        private ?string $channelName = null,
        int|string|Level $level = Level::Debug,
    ) {
        parent::__construct($level, bubble: true);
    }

    /**
     * Appends the record's line; a failure is written to PHP's error log and the app carries on (fail open).
     */
    protected function write(LogRecord $record): void
    {
        try {
            $this->outbox->append($this->lineBuilder->build($record, $this->channelName));
        } catch (\Throwable $error) {
            error_log("[simpledatalogs] a log line was not queued: " . $error->getMessage());
        }
    }
}
