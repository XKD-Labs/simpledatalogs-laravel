<?php

namespace SimpleDataLogs\Laravel\Tests\Telemetry;

use SimpleDataLogs\Laravel\Telemetry\Entry;
use SimpleDataLogs\Laravel\Telemetry\Normalizer;

use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * Exceptions: reported ones arrive through the handler, inside their bundle or on their own, within the caps.
 */
class ExceptionTelemetryTest extends TelemetryTestCase
{
    /**
     * The routes the tests call.
     */
    protected function defineRoutes($router): void
    {
        $router->get("/many-errors", function () {
            foreach (range(1, 7) as $errorNumber) {
                report(new \RuntimeException("Sync step {$errorNumber} failed"));
            }

            return "done";
        });
    }

    /**
     * Groups every exception under one fingerprint.
     */
    protected function useFingerprint($app): void
    {
        $app["config"]->set("simpledatalogs.fingerprint", fn (\Throwable $exception): string => "payments");
    }

    public function test_an_exception_reported_outside_any_entry_point_is_sent_on_its_own(): void
    {
        report(new \RuntimeException("Webhook signature mismatch"));

        $exception = $this->onlyBundle("exception");

        $this->assertSame(\RuntimeException::class, $exception["type"]);
        $this->assertSame("Webhook signature mismatch", $exception["message"]);
        $this->assertTrue($exception["handled"]);
        $this->assertNotEmpty($exception["frames"]);
        $this->assertSame("2026.10.3", $exception["release"]);
        $this->assertSame("testing", $exception["props"]["app_env"]);
        $this->assertMatchesRegularExpression("/^[0-9a-f-]{36}$/", $exception["event_id"]);
        $this->assertArrayNotHasKey("start_ms", $exception);
    }

    #[DefineEnvironment("useFingerprint")]
    public function test_the_configured_fingerprint_callback_groups_exceptions(): void
    {
        report(new \RuntimeException("Card declined"));

        $this->assertSame("payments", $this->onlyBundle("exception")["fingerprint"]);
    }

    public function test_a_bundle_keeps_at_most_five_exceptions_and_counts_the_rest(): void
    {
        $this->get("/many-errors")->assertOk();

        $bundle = $this->onlyBundle("request");

        $this->assertCount(5, $bundle["exceptions"]);
        $this->assertSame(2, $bundle["props"]["exceptions_dropped"]);
        $this->assertSame("Sync step 1 failed", $bundle["exceptions"][0]["message"]);
    }

    public function test_frames_are_relative_marked_in_app_and_capped_at_fifty_and_eight_kilobytes(): void
    {
        $normalizer = new Normalizer("/srv/shop", "testing");

        // A throw inside the app, called from the framework.
        $exception = new \RuntimeException("Card declined");
        $this->setThrowSite($exception, "/srv/shop/app/Services/Payments.php", 88, [
            ["file" => "/srv/shop/vendor/laravel/framework/src/Pipeline.php", "line" => 12, "class" => "App\\Services\\Payments", "type" => "->", "function" => "charge"],
            ["file" => "/srv/shop/routes/web.php", "line" => 5, "function" => "handle", "class" => "Illuminate\\Pipeline\\Pipeline", "type" => "->"],
        ]);

        $frames = $normalizer->exception($exception, true, 0.0)["frames"];

        $this->assertSame(["file" => "app/Services/Payments.php", "line" => 88, "function" => "App\\Services\\Payments::charge", "in_app" => true], $frames[0]);
        $this->assertSame("vendor/laravel/framework/src/Pipeline.php", $frames[1]["file"]);
        $this->assertFalse($frames[1]["in_app"]);
        $this->assertTrue($frames[2]["in_app"]);
        $this->assertSame("{main}", $frames[2]["function"]);

        // A deep stack stops at 50 frames, a wide one at 8 KB.
        $deepTrace = array_fill(0, 200, ["file" => "/srv/shop/app/Deep.php", "line" => 1, "function" => "recurse"]);
        $this->setThrowSite($exception, "/srv/shop/app/Deep.php", 1, $deepTrace);
        $this->assertCount(Normalizer::MAX_FRAMES, $normalizer->exception($exception, true, 0.0)["frames"]);

        $wideTrace = array_fill(0, 40, ["file" => "/srv/shop/app/" . str_repeat("Wide/", 60) . "File.php", "line" => 1, "function" => "wide"]);
        $this->setThrowSite($exception, "/srv/shop/app/Wide.php", 1, $wideTrace);
        $wideFrames = $normalizer->exception($exception, true, 0.0)["frames"];
        $this->assertLessThan(40, count($wideFrames));
        $this->assertLessThanOrEqual(Normalizer::MAX_STACK_BYTES, strlen(json_encode($wideFrames, JSON_UNESCAPED_SLASHES)));
    }

    public function test_an_uncaught_console_error_left_open_is_written_as_an_error_at_termination(): void
    {
        $recorder = $this->app->make(\SimpleDataLogs\Laravel\Telemetry\Recorder::class);
        $recorder->begin("command", "reports:crash", identity: "reports:crash");
        report(new \RuntimeException("Disk full"));

        $this->app->terminate();

        $bundle = $this->onlyBundle("command");
        $this->assertSame("error", $bundle["status"]);
        $this->assertFalse($bundle["exceptions"][0]["handled"]);
        $this->assertInstanceOf(Entry::class, $recorder->begin("command", "next"));
    }

    /**
     * Points an exception's file, line and trace at a fake app, as a real throw there would.
     *
     * @param array<int, array<string, mixed>> $traceFrames
     */
    private function setThrowSite(\Exception $exception, string $filePath, int $lineNumber, array $traceFrames): void
    {
        (new \ReflectionProperty(\Exception::class, "file"))->setValue($exception, $filePath);
        (new \ReflectionProperty(\Exception::class, "line"))->setValue($exception, $lineNumber);
        (new \ReflectionProperty(\Exception::class, "trace"))->setValue($exception, $traceFrames);
    }
}
