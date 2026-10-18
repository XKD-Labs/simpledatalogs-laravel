<?php

namespace SimpleDataLogs\Laravel\Console;

use SimpleDataLogs\Laravel\Shipper;

use Illuminate\Console\Command;

/**
 * Ships the outbox for about a minute; schedule it every minute, so lines leave within ~5 seconds and batch across requests.
 */
class ShipCommand extends Command
{
    // Pause between passes while the outbox is empty, in seconds.
    private const IDLE_SECONDS = 5;

    /**
     * The command line: --once ships one pass and exits.
     *
     * @var string
     */
    protected $signature = "simpledatalogs:ship {--once : Ship one pass, then exit}";

    /**
     * The one-line description in `php artisan list`.
     *
     * @var string
     */
    protected $description = "Send queued log lines to simpledatalogs";

    /**
     * Ships until the configured number of seconds pass, printing a running total.
     */
    public function handle(Shipper $shipper): int
    {
        $startedAt = time();
        $deadline = $startedAt + (int) config("simpledatalogs.ship_seconds", 55);
        $shippedCount = 0;

        do {
            // One pass sends everything queued so far, or stops at the deadline.
            $passCount = $shipper->ship($deadline);
            $shippedCount += $passCount;

            if ($passCount > 0) {
                $this->line("[simpledatalogs] shipped {$shippedCount} lines in " . (time() - $startedAt) . "s");
            }

            if ($this->option("once")) {
                break;
            }

            sleep(min(self::IDLE_SECONDS, max($deadline - time(), 0)));
        } while (time() < $deadline);

        $this->info("[simpledatalogs] done: {$shippedCount} lines shipped in " . (time() - $startedAt) . "s");

        return self::SUCCESS;
    }
}
