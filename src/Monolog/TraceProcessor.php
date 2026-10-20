<?php

namespace SimpleDataLogs\Laravel\Monolog;

use SimpleDataLogs\Laravel\Telemetry\Recorder;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Stamps the running entry point's `trace_id` and `span_id` on every log record, so log lines link to their trace.
 */
class TraceProcessor implements ProcessorInterface
{
    /**
     * Keeps the recorder that knows the running trace.
     */
    public function __construct(private Recorder $recorder)
    {
    }

    /**
     * Adds the trace ids to the record's extra; outside any entry point the record passes unchanged.
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        try {
            $traceIds = $this->recorder->currentTraceIds();

            if ($traceIds === null) {
                return $record;
            }

            return $record->with(extra: [...$record->extra, "trace_id" => $traceIds["trace_id"], "span_id" => $traceIds["span_id"]]);
        } catch (\Throwable) {
            return $record;
        }
    }
}
