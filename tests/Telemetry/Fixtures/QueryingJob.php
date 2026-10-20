<?php

namespace SimpleDataLogs\Laravel\Tests\Telemetry\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * A queued job that runs one query, so its bundle has a child.
 */
class QueryingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Runs the query.
     */
    public function handle(): void
    {
        DB::select("select ? as order_id", [8812]);
    }
}
