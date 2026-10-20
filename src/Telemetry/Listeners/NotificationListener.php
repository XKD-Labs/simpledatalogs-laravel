<?php

namespace SimpleDataLogs\Laravel\Telemetry\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;

/**
 * Notifications: the notification class, the channel and the send time; the notifiable and the payload are never read.
 */
class NotificationListener extends Listener
{
    // Start times waiting for their sent event are dropped past this many (a channel that never answered).
    private const MAX_WAITING_NOTIFICATIONS = 100;

    // Start times of notifications being sent, by notification and channel.
    /** @var array<string, float> */
    private array $sendingStartedAt = [];

    /**
     * Listens for notifications leaving, sent and failed.
     */
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(NotificationSending::class, $this->handleNotificationSending(...));
        $events->listen(NotificationSent::class, fn (NotificationSent $event) => $this->recordNotification($event->notification, $event->channel, "ok"));
        $events->listen(NotificationFailed::class, fn (NotificationFailed $event) => $this->recordNotification($event->notification, $event->channel, "error"));
    }

    /**
     * Notes when a notification starts sending on a channel, only while an entry point is recorded.
     */
    public function handleNotificationSending(NotificationSending $event): void
    {
        if ($this->recorder->current() === null) {
            return;
        }

        if (count($this->sendingStartedAt) >= self::MAX_WAITING_NOTIFICATIONS) {
            $this->sendingStartedAt = [];
        }

        $this->sendingStartedAt[$this->sendingKey($event->notification, $event->channel)] = microtime(true);
    }

    /**
     * Records the notification under its class name with its channel.
     */
    private function recordNotification(object $notification, mixed $channel, string $status): void
    {
        $this->safely("a notification", function () use ($notification, $channel, $status): void {
            $sendingKey = $this->sendingKey($notification, $channel);
            $startedAt = $this->sendingStartedAt[$sendingKey] ?? microtime(true);
            unset($this->sendingStartedAt[$sendingKey]);

            $this->recorder->recordTimedChild("notification", $notification::class, $startedAt, ["channel" => $this->channelName($channel)], $status);
        });
    }

    /**
     * The key pairing a notification's sending and sent events on one channel.
     */
    private function sendingKey(object $notification, mixed $channel): string
    {
        return spl_object_id($notification) . "\0" . $this->channelName($channel);
    }

    /**
     * A channel as text: its name, or its class for a custom channel object.
     */
    private function channelName(mixed $channel): string
    {
        return is_object($channel) ? $channel::class : (string) $channel;
    }
}
