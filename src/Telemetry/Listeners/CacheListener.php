<?php

namespace SimpleDataLogs\Laravel\Telemetry\Listeners;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Cache: hit, miss, write and forget counts per store; key patterns only when `telemetry.cache_keys` is on.
 */
class CacheListener extends Listener
{
    /**
     * Listens for the four cache outcomes.
     */
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(CacheHit::class, fn (CacheHit $event) => $this->recorder->recordCache("hit", $event->storeName, $event->key));
        $events->listen(CacheMissed::class, fn (CacheMissed $event) => $this->recorder->recordCache("miss", $event->storeName, $event->key));
        $events->listen(KeyWritten::class, fn (KeyWritten $event) => $this->recorder->recordCache("write", $event->storeName, $event->key));
        $events->listen(KeyForgotten::class, fn (KeyForgotten $event) => $this->recorder->recordCache("forget", $event->storeName, $event->key));
    }
}
