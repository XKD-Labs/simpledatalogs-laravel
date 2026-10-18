<?php

namespace SimpleDataLogs\Laravel;

use Illuminate\Log\Logger;

/**
 * The channel tap: `'tap' => [ForwardToSimpleDataLogs::class . ':partner_api']` adds a forwarding handler beside the channel's own handlers.
 * Laravel names every Monolog logger after APP_ENV, so the channel's name comes from the tap parameter.
 */
class ForwardToSimpleDataLogs
{
    /**
     * Keeps what every channel's handler shares.
     */
    public function __construct(private LineBuilder $lineBuilder, private Outbox $outbox)
    {
    }

    /**
     * Adds a handler for this channel to its Monolog logger; without a parameter the record's own channel is used.
     */
    public function __invoke(Logger $logger, ?string $channelName = null): void
    {
        $logger->getLogger()->pushHandler(new SimpleDataLogsHandler(
            lineBuilder: $this->lineBuilder,
            outbox: $this->outbox,
            channelName: $channelName,
        ));
    }
}
