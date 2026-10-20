<?php

namespace SimpleDataLogs\Laravel\Tests\Telemetry\Fixtures;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A mail notification whose content may never reach a bundle.
 */
class InvoicePaid extends Notification
{
    /**
     * Sends by mail.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ["mail"];
    }

    /**
     * The mail message.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())->subject("Invoice paid")->line("Thanks for paying invoice 77.");
    }
}
