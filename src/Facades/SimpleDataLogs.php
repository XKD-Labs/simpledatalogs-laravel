<?php

namespace SimpleDataLogs\Laravel\Facades;

use SimpleDataLogs\Laravel\EventTracker;

use Illuminate\Support\Facades\Facade;

/**
 * The `SimpleDataLogs` facade: `SimpleDataLogs::track("signup", ["plan" => "pro"])` and `SimpleDataLogs::span("render-pdf", fn () => …)`.
 *
 * @method static void track(string $eventName, array $props = [], int|string|null $userId = null)
 * @method static void setUserId(int|string|null $userId)
 * @method static string|null userId()
 * @method static mixed span(string $spanName, callable $callback, array $props = [])
 *
 * @see \SimpleDataLogs\Laravel\EventTracker
 */
class SimpleDataLogs extends Facade
{
    /**
     * The container binding behind the facade.
     */
    protected static function getFacadeAccessor(): string
    {
        return EventTracker::class;
    }
}
