<?php

namespace SimpleDataLogs\Laravel\Telemetry\Listeners;

use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Mail: the mailable (or notification) class and the send time; addresses, subjects and bodies are never read.
 */
class MailListener extends Listener
{
    // Start times waiting for their sent event are dropped past this many (a listener that cancelled sends).
    private const MAX_WAITING_MESSAGES = 100;

    // Start times of messages being sent, innermost last.
    /** @var array<int, float> */
    private array $sendingStartedAt = [];

    /**
     * Listens for messages leaving and sent.
     */
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(MessageSending::class, $this->handleMessageSending(...));
        $events->listen(MessageSent::class, $this->handleMessageSent(...));
    }

    /**
     * Notes when a message starts sending, only while an entry point is recorded.
     */
    public function handleMessageSending(MessageSending $event): void
    {
        if ($this->recorder->current() === null) {
            return;
        }

        if (count($this->sendingStartedAt) >= self::MAX_WAITING_MESSAGES) {
            $this->sendingStartedAt = [];
        }

        $this->sendingStartedAt[] = microtime(true);
    }

    /**
     * Records the sent message under its class name.
     */
    public function handleMessageSent(MessageSent $event): void
    {
        $this->safely("a mail", function () use ($event): void {
            $startedAt = array_pop($this->sendingStartedAt) ?? microtime(true);
            $mailName = $event->data["__laravel_mailable"] ?? $event->data["__laravel_notification"] ?? "mail";

            $this->recorder->recordTimedChild("mail", is_string($mailName) ? $mailName : "mail", $startedAt);
        });
    }
}
