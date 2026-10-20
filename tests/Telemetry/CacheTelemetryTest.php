<?php

namespace SimpleDataLogs\Laravel\Tests\Telemetry;

use Illuminate\Support\Facades\Cache;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * Cache: counts per outcome and store; key patterns only with `telemetry.cache_keys`, masked.
 */
class CacheTelemetryTest extends TelemetryTestCase
{
    /**
     * The routes the tests call.
     */
    protected function defineRoutes($router): void
    {
        $router->get("/profile", function () {
            Cache::put("user:4242:profile", "zz-cached-value", 60);
            Cache::get("user:4242:profile");
            Cache::get("user:4242:profile");
            Cache::get("session:9f86d081884c7d659a2feaa0c55ad015");
            Cache::forget("user:4242:profile");

            return "profile";
        });
    }

    /**
     * Turns cache key patterns on.
     */
    protected function sendCacheKeys($app): void
    {
        $app["config"]->set("simpledatalogs.telemetry.cache_keys", true);
    }

    public function test_cache_sends_counts_per_outcome_and_store_without_keys_by_default(): void
    {
        $this->get("/profile")->assertOk();

        $bundle = $this->onlyBundle("request");

        $this->assertEquals(["hits" => 2, "misses" => 1, "writes" => 1, "forgets" => 1], $bundle["summary"]["cache"]);

        $cacheByName = array_column($this->children($bundle, "cache"), null, "name");
        $this->assertSame(2, $cacheByName["hit"]["count"]);
        $this->assertSame(["store" => "array"], $cacheByName["hit"]["props"]);

        $this->assertStringNotContainsString("4242", $this->outboxText());
        $this->assertStringNotContainsString("user:", $this->outboxText());
        $this->assertStringNotContainsString("zz-cached-value", $this->outboxText());
    }

    #[DefineEnvironment("sendCacheKeys")]
    public function test_cache_key_patterns_are_masked_when_switched_on(): void
    {
        $this->get("/profile")->assertOk();

        $cacheKeys = array_column(array_column($this->children($this->onlyBundle("request"), "cache"), "props"), "key");

        $this->assertContains("user:*:profile", $cacheKeys);
        $this->assertContains("session:*", $cacheKeys);
        $this->assertStringNotContainsString("4242", $this->outboxText());
        $this->assertStringNotContainsString("zz-cached-value", $this->outboxText());
    }
}
