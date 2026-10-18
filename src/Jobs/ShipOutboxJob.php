<?php

namespace SimpleDataLogs\Laravel\Jobs;

use SimpleDataLogs\Laravel\Shipper;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * Ships the outbox from a queue worker (`SIMPLEDATALOGS_DELIVERY=queue`); one waits in the queue at a time.
 */
class ShipOutboxJob implements ShouldQueue, ShouldBeUnique
{
    use InteractsWithQueue;
    use Queueable;

    /**
     * Seconds one run keeps shipping.
     *
     * @var int
     */
    public int $shipSeconds = 20;

    /**
     * Seconds the unique lock holds, so a burst of requests queues one job.
     *
     * @var int
     */
    public int $uniqueFor = 60;

    /**
     * One try: the next request queues another, and the outbox keeps every line until a 202.
     *
     * @var int
     */
    public int $tries = 1;

    /**
     * Puts the job on the configured queue, or the default one.
     */
    public function __construct()
    {
        $this->onQueue(config("simpledatalogs.queue"));
    }

    /**
     * Ships until the outbox is empty or the run's seconds pass.
     */
    public function handle(Shipper $shipper): void
    {
        $shipper->ship(time() + $this->shipSeconds);
    }
}
