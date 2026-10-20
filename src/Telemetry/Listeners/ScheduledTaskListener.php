<?php

namespace SimpleDataLogs\Laravel\Telemetry\Listeners;

use SimpleDataLogs\Laravel\Telemetry\Entry;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;

/**
 * Scheduled tasks: command line, cron expression, `interval_s` from it, duration and status, skipped runs included.
 */
class ScheduledTaskListener extends Listener
{
    // Seconds in a minute, hour, day, week, 30-day month and year, for the interval table.
    private const MINUTE = 60;
    private const HOUR = 3600;
    private const DAY = 86400;
    private const WEEK = 604800;
    private const MONTH = 2592000;
    private const YEAR = 31536000;

    /**
     * Listens for a task starting, finishing, failing and being skipped.
     */
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(ScheduledTaskStarting::class, $this->handleTaskStarting(...));
        $events->listen(ScheduledTaskFinished::class, $this->handleTaskFinished(...));
        $events->listen(ScheduledTaskFailed::class, $this->handleTaskFailed(...));
        $events->listen(ScheduledTaskSkipped::class, $this->handleTaskSkipped(...));
    }

    /**
     * Opens the task's entry.
     */
    public function handleTaskStarting(ScheduledTaskStarting $event): void
    {
        $this->safely("a scheduled task", fn () => $this->beginTask($event->task));
    }

    /**
     * Writes the task's bundle; a non-zero exit waits for the failed event that follows, so it carries the error.
     */
    public function handleTaskFinished(ScheduledTaskFinished $event): void
    {
        $this->safely("a scheduled task", function () use ($event): void {
            $entry = $this->recorder->openEntry("task", $event->task);

            if ($entry === null) {
                return;
            }

            $exitCode = (int) $event->task->exitCode;

            if ($exitCode === 0 || $event->task->runInBackground) {
                $this->recorder->finish($entry);

                return;
            }

            // The scheduler raises a failure right after this; the duration is fixed now.
            $entry->status = "error";
            $entry->props["exit_code"] = $exitCode;
            $entry->durationMs = $entry->offsetMs(microtime(true));
        });
    }

    /**
     * Writes a failed task's bundle as an error, its exception marked unhandled.
     */
    public function handleTaskFailed(ScheduledTaskFailed $event): void
    {
        $this->safely("a failed scheduled task", function () use ($event): void {
            $entry = $this->recorder->openEntry("task", $event->task);

            if ($entry === null) {
                return;
            }

            $entry->status = "error";
            $this->recorder->markUnhandled($entry, $event->exception, true);
            $this->recorder->finish($entry);
        });
    }

    /**
     * Writes a skipped run (a filter said no) as an instant bundle marked `skipped`.
     */
    public function handleTaskSkipped(ScheduledTaskSkipped $event): void
    {
        $this->safely("a skipped scheduled task", function () use ($event): void {
            $entry = $this->beginTask($event->task);
            $entry->props["skipped"] = true;
            $this->recorder->finish($entry);
        });
    }

    /**
     * Opens an entry for a task with its expression and interval.
     */
    private function beginTask(Event $task): Entry
    {
        $entry = $this->recorder->begin("task", self::taskName($task), identity: $task);
        $entry->props["expression"] = (string) $task->expression;

        $intervalSeconds = self::intervalSeconds($task);

        if ($intervalSeconds !== null) {
            $entry->props["interval_s"] = $intervalSeconds;
        }

        return $entry;
    }

    /**
     * The task's command line without the PHP binary and `artisan`; a closure task's description, else "Callback".
     */
    public static function taskName(Event $task): string
    {
        if (! is_string($task->command) || trim($task->command) === "") {
            return (string) $task->getSummaryForDisplay();
        }

        $commandLine = preg_replace("/^.*?['\"]?artisan['\"]?\\s+/", "", trim($task->command), 1) ?? $task->command;

        return trim($commandLine) === "" ? trim($task->command) : trim($commandLine);
    }

    /**
     * The seconds between runs, read from the cron expression by a fixed table; null when it doesn't fit the table.
     */
    public static function intervalSeconds(Event $task): ?int
    {
        // Sub-minute tasks (everyTenSeconds and friends) repeat inside the minute.
        if (method_exists($task, "isRepeatable") && $task->isRepeatable()) {
            return (int) $task->repeatSeconds;
        }

        $cronFields = preg_split("/\\s+/", trim((string) $task->expression));

        if ($cronFields === false || count($cronFields) !== 5) {
            return null;
        }

        [$minuteField, $hourField, $dayOfMonthField, $monthField, $dayOfWeekField] = $cronFields;

        // Minutes: every minute, or every n minutes, whatever the hours.
        if ($minuteField === "*") {
            return self::MINUTE;
        }

        if (($minuteStep = self::step($minuteField)) !== null) {
            return self::MINUTE * $minuteStep;
        }

        $minuteRunCount = self::valueCount($minuteField);

        if ($minuteRunCount === null) {
            return null;
        }

        // Hours: hourly, or every n hours.
        if ($hourField === "*") {
            return intdiv(self::HOUR, $minuteRunCount);
        }

        if (($hourStep = self::step($hourField)) !== null) {
            return intdiv(self::HOUR * $hourStep, $minuteRunCount);
        }

        $hourRunCount = self::valueCount($hourField);

        if ($hourRunCount === null) {
            return null;
        }

        // Days: daily (weekday ranges stay daily), or weekly on one day.
        if ($dayOfMonthField === "*" && $monthField === "*") {
            if ($dayOfWeekField === "*") {
                return intdiv(self::DAY, $hourRunCount * $minuteRunCount);
            }

            return self::valueCount($dayOfWeekField) === 1 ? self::WEEK : self::DAY;
        }

        // Months: monthly on one day, every n months, or a few months a year.
        if (self::valueCount($dayOfMonthField) !== 1) {
            return null;
        }

        if ($monthField === "*") {
            return self::MONTH;
        }

        if (($monthStep = self::step($monthField)) !== null) {
            return self::MONTH * $monthStep;
        }

        $monthRunCount = self::valueCount($monthField);

        return $monthRunCount === null ? null : intdiv(self::YEAR, $monthRunCount);
    }

    /**
     * The n of a `*` + `/n` field, else null.
     */
    private static function step(string $cronField): ?int
    {
        return preg_match("/^\\*\\/(\\d+)$/", $cronField, $stepParts) === 1 && (int) $stepParts[1] > 0 ? (int) $stepParts[1] : null;
    }

    /**
     * How many values a field lists (`5`, `1,15`, `1-5`), else null.
     */
    private static function valueCount(string $cronField): ?int
    {
        $valueCount = 0;

        foreach (explode(",", $cronField) as $fieldPart) {
            if (preg_match("/^\\d+$/", $fieldPart) === 1) {
                $valueCount++;
            } elseif (preg_match("/^(\\d+)-(\\d+)$/", $fieldPart, $rangeParts) === 1 && (int) $rangeParts[2] >= (int) $rangeParts[1]) {
                $valueCount += (int) $rangeParts[2] - (int) $rangeParts[1] + 1;
            } else {
                return null;
            }
        }

        return $valueCount > 0 ? $valueCount : null;
    }
}
