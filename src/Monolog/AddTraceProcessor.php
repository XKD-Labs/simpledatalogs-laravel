<?php

namespace SimpleDataLogs\Laravel\Monolog;

use Illuminate\Log\Logger;

/**
 * The channel tap the package adds to every configured log channel: it pushes the trace processor once.
 */
class AddTraceProcessor
{
    /**
     * Keeps the processor every channel shares.
     */
    public function __construct(private TraceProcessor $traceProcessor)
    {
    }

    /**
     * Pushes the processor onto the channel's Monolog logger unless it already has it.
     */
    public function __invoke(Logger $logger): void
    {
        try {
            $monologLogger = $logger->getLogger();

            if (! method_exists($monologLogger, "pushProcessor") || in_array($this->traceProcessor, $monologLogger->getProcessors(), true)) {
                return;
            }

            $monologLogger->pushProcessor($this->traceProcessor);
        } catch (\Throwable $error) {
            error_log("[simpledatalogs] the trace processor was not added to a log channel: " . $error->getMessage());
        }
    }
}
