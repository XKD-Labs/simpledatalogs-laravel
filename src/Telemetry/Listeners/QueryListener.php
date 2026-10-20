<?php

namespace SimpleDataLogs\Laravel\Telemetry\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;

/**
 * Queries: the SQL Laravel already gives with `?` for bindings, the connection name and the time; bindings are never read.
 */
class QueryListener extends Listener
{
    /**
     * Listens for every executed query.
     */
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(QueryExecuted::class, $this->handleQueryExecuted(...));
    }

    /**
     * Records the query under the running entry point (the recorder folds identical SQL).
     */
    public function handleQueryExecuted(QueryExecuted $event): void
    {
        $this->recorder->recordQuery((string) $event->sql, (float) $event->time, $event->connectionName);
    }
}
