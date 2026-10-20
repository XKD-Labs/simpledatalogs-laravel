<?php

namespace SimpleDataLogs\Laravel\Tests\Telemetry\Fixtures;

use Illuminate\Mail\Mailable;

/**
 * A mailable with a subject and an HTML body, neither of which may reach a bundle.
 */
class OrderShipped extends Mailable
{
    /**
     * Builds the message.
     */
    public function build(): static
    {
        return $this->subject("Your order 8812 shipped")->html("<p>Hello buyer</p>");
    }
}
