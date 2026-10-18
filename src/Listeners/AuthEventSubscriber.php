<?php

namespace SimpleDataLogs\Laravel\Listeners;

use SimpleDataLogs\Laravel\EventTracker;

use Illuminate\Auth\Events\Login;
use Illuminate\Events\Dispatcher;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Opt-in (`SIMPLEDATALOGS_TRACK_AUTH=true`): sends user_logged_in, user_logged_out and user_registered, and sets user_id from the user.
 */
class AuthEventSubscriber
{
    /**
     * The auth events and their handlers.
     *
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => "handleLogin",
            Logout::class => "handleLogout",
            Registered::class => "handleRegistered",
        ];
    }

    /**
     * A sign-in: later events carry this user's id.
     */
    public function handleLogin(Login $event): void
    {
        $this->trackForUser("user_logged_in", $event->user, ["guard" => $event->guard, "remember" => $event->remember]);
    }

    /**
     * A sign-up: later events carry the new user's id.
     */
    public function handleRegistered(Registered $event): void
    {
        $this->trackForUser("user_registered", $event->user, []);
    }

    /**
     * A sign-out: the event carries the user, later events none.
     */
    public function handleLogout(Logout $event): void
    {
        $tracker = $this->tracker();

        $tracker->track("user_logged_out", ["guard" => $event->guard], $event->user?->getAuthIdentifier());
        $tracker->setUserId(null);
    }

    /**
     * Sets the user's id on the request's tracker, then sends the event.
     *
     * @param array<string, mixed> $props
     */
    private function trackForUser(string $eventName, Authenticatable $user, array $props): void
    {
        $tracker = $this->tracker();

        $tracker->setUserId($user->getAuthIdentifier());
        $tracker->track($eventName, $props);
    }

    /**
     * The tracker of the current request; resolved per call, since it is scoped (Octane resets it).
     */
    private function tracker(): EventTracker
    {
        return app(EventTracker::class);
    }
}
