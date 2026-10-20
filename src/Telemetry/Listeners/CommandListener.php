<?php

namespace SimpleDataLogs\Laravel\Telemetry\Listeners;

use SimpleDataLogs\Laravel\Telemetry\Recorder;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;

/**
 * Artisan commands: name, exit code and duration; arguments and options are never sent, and workers are skipped.
 */
class CommandListener extends Listener
{
    /**
     * Keeps the recorder and the commands never recorded (the ship command, the scheduler, long-running workers).
     *
     * @param array<int, string> $ignoredCommandNames
     */
    public function __construct(Recorder $recorder, private array $ignoredCommandNames = [])
    {
        parent::__construct($recorder);
    }

    /**
     * Listens for a command starting and finishing.
     */
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(CommandStarting::class, $this->handleCommandStarting(...));
        $events->listen(CommandFinished::class, $this->handleCommandFinished(...));
    }

    /**
     * Opens the command's entry unless it is ignored.
     */
    public function handleCommandStarting(CommandStarting $event): void
    {
        $this->safely("a command", function () use ($event): void {
            $commandName = trim((string) $event->command);

            if ($commandName === "" || in_array($commandName, $this->ignoredCommandNames, true)) {
                return;
            }

            $this->recorder->begin("command", $commandName, identity: $commandName);
        });
    }

    /**
     * Writes the command's bundle with its exit code; non-zero is an error.
     */
    public function handleCommandFinished(CommandFinished $event): void
    {
        $this->safely("a command", function () use ($event): void {
            $entry = $this->recorder->openEntry("command", trim((string) $event->command));

            if ($entry === null) {
                return;
            }

            $entry->props["exit_code"] = $event->exitCode;

            if ($event->exitCode !== 0) {
                $entry->status = "error";
            }

            $this->recorder->finish($entry);
        });
    }
}
