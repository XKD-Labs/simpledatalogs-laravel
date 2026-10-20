<?php

namespace SimpleDataLogs\Laravel\Tests\Telemetry;

use SimpleDataLogs\Laravel\Tests\Telemetry\Fixtures\InvoicePaid;
use SimpleDataLogs\Laravel\Tests\Telemetry\Fixtures\OrderShipped;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/**
 * Mail and notifications: class name, channel and duration; addresses, subjects and bodies never leave.
 */
class MailTelemetryTest extends TelemetryTestCase
{
    /**
     * Sends mail into the array mailer.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app["config"]->set("mail.default", "array");
    }

    /**
     * The routes the tests call.
     */
    protected function defineRoutes($router): void
    {
        $router->get("/ship", function () {
            Mail::to("zz-buyer@example.test")->send(new OrderShipped());

            return "shipped";
        });

        $router->get("/invoice", function () {
            Notification::route("mail", "zz-payer@example.test")->notifyNow(new InvoicePaid());

            return "invoiced";
        });
    }

    public function test_a_mailable_is_a_child_named_by_its_class(): void
    {
        $this->get("/ship")->assertOk();

        $bundle = $this->onlyBundle("request");
        [$mailChild] = $this->children($bundle, "mail");

        $this->assertSame(OrderShipped::class, $mailChild["name"]);
        $this->assertSame(1, $bundle["summary"]["mail"]["count"]);
        $this->assertStringNotContainsString("zz-buyer", $this->outboxText());
        $this->assertStringNotContainsString("shipped\"", $this->outboxText());
        $this->assertStringNotContainsString("Hello buyer", $this->outboxText());
    }

    public function test_a_notification_is_a_child_with_its_channel_and_its_mail(): void
    {
        $this->get("/invoice")->assertOk();

        $bundle = $this->onlyBundle("request");
        [$notificationChild] = $this->children($bundle, "notification");
        [$mailChild] = $this->children($bundle, "mail");

        $this->assertSame(InvoicePaid::class, $notificationChild["name"]);
        $this->assertSame(["channel" => "mail"], $notificationChild["props"]);
        $this->assertSame(InvoicePaid::class, $mailChild["name"]);
        $this->assertSame(1, $bundle["summary"]["notifications"]["count"]);
        $this->assertStringNotContainsString("zz-payer", $this->outboxText());
        $this->assertStringNotContainsString("invoice 77", $this->outboxText());
    }
}
