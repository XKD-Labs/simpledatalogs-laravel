<?php

namespace SimpleDataLogs\Laravel\Tests;

use SimpleDataLogs\Laravel\SimpleDataLogsServiceProvider;

use Orchestra\Testbench\TestCase as OrchestraTestCase;

/**
 * A Laravel app with the forwarder installed and its outbox in a fresh temporary folder.
 */
abstract class TestCase extends OrchestraTestCase
{
    // The outbox folder of the running test.
    protected string $outboxPath;

    /**
     * Gives every test its own outbox before the app boots.
     */
    protected function setUp(): void
    {
        $this->outboxPath = sys_get_temp_dir() . "/sdl-outbox-" . bin2hex(random_bytes(6));
        parent::setUp();
    }

    /**
     * Removes the test's outbox.
     */
    protected function tearDown(): void
    {
        exec("rm -rf " . escapeshellarg($this->outboxPath));
        parent::tearDown();
    }

    /**
     * Loads the package's service provider.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [SimpleDataLogsServiceProvider::class];
    }

    /**
     * Points the package at the test outbox and an API log channel.
     */
    protected function defineEnvironment($app): void
    {
        $app["config"]->set("app.env", "testing");
        $app["config"]->set("simpledatalogs.key", "sdl_sec_test");
        $app["config"]->set("simpledatalogs.url", "http://ingest.test/v1/ingest");
        $app["config"]->set("simpledatalogs.outbox_path", $this->outboxPath);
        $app["config"]->set("simpledatalogs.message_fields", ["endpoint", "success:bool", "attempts:int"]);

        // Tests ship explicitly; delivery after the response is tested on its own.
        $app["config"]->set("simpledatalogs.delivery", "schedule");

        $app["config"]->set("logging.channels.partner_api", [
            "driver" => "single",
            "path" => sys_get_temp_dir() . "/partner-api-" . basename($this->outboxPath) . ".log",
            "level" => "debug",
            "tap" => [\SimpleDataLogs\Laravel\ForwardToSimpleDataLogs::class . ":partner_api"],
        ]);
    }
}
