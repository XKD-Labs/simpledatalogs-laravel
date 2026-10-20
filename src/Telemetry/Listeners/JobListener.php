<?php

namespace SimpleDataLogs\Laravel\Telemetry\Listeners;

use SimpleDataLogs\Laravel\Telemetry\Recorder;
use SimpleDataLogs\Laravel\Telemetry\TraceContext;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobReleasedAfterException;

/**
 * Jobs: class, queue, connection, attempts, duration and status, joined to the dispatching trace through `__sdl_trace`.
 */
class JobListener extends Listener
{
    /**
     * Keeps the recorder and the job classes never recorded (the package's own ship job).
     *
     * @param array<int, string> $ignoredJobNames
     */
    public function __construct(Recorder $recorder, private array $ignoredJobNames = [])
    {
        parent::__construct($recorder);
    }

    /**
     * Listens for a job starting, throwing, finishing, failing and being released after an exception.
     */
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(JobProcessing::class, $this->handleJobProcessing(...));
        $events->listen(JobExceptionOccurred::class, $this->handleJobExceptionOccurred(...));
        $events->listen(JobProcessed::class, $this->handleJobProcessed(...));
        $events->listen(JobFailed::class, $this->handleJobFailed(...));
        $events->listen(JobReleasedAfterException::class, $this->handleJobReleasedAfterException(...));
    }

    /**
     * Opens the job's entry; a trace in the payload makes it a remote child of the request that dispatched it.
     */
    public function handleJobProcessing(JobProcessing $event): void
    {
        $this->safely("a job", function () use ($event): void {
            $jobName = (string) $event->job->resolveName();

            if (in_array($jobName, $this->ignoredJobNames, true)) {
                return;
            }

            $remoteParent = TraceContext::fromPayload($event->job->payload()["__sdl_trace"] ?? null);
            $entry = $this->recorder->begin("job", $jobName, $remoteParent, identity: $event->job);

            $entry->props = [
                "queue" => (string) $event->job->getQueue(),
                "connection" => (string) $event->connectionName,
                "attempts" => (int) $event->job->attempts(),
            ];
        });
    }

    /**
     * Keeps the exception a job threw as unhandled; the worker reports it only after the job's bundle is written.
     */
    public function handleJobExceptionOccurred(JobExceptionOccurred $event): void
    {
        $this->safely("a job exception", function () use ($event): void {
            $entry = $this->recorder->openEntry("job", $event->job);

            if ($entry !== null) {
                $this->recorder->markUnhandled($entry, $event->exception, true);
            }
        });
    }

    /**
     * Writes the job's bundle once it ran; a job that failed itself or was released says so.
     */
    public function handleJobProcessed(JobProcessed $event): void
    {
        $this->safely("a job", function () use ($event): void {
            $entry = $this->recorder->openEntry("job", $event->job);

            if ($entry === null) {
                return;
            }

            if ($event->job->hasFailed()) {
                $entry->status = "error";
            }

            if ($event->job->isReleased()) {
                $entry->props["released"] = true;
            }

            $this->recorder->finish($entry);
        });
    }

    /**
     * Writes a failed job's bundle as an error, its exception marked unhandled.
     */
    public function handleJobFailed(JobFailed $event): void
    {
        $this->finishWithError($event->job, $event->exception, false);
    }

    /**
     * Writes the bundle of a job that threw and goes back on the queue for another attempt.
     */
    public function handleJobReleasedAfterException(JobReleasedAfterException $event): void
    {
        $this->finishWithError($event->job, null, true);
    }

    /**
     * Ends a job's entry as an error, with its exception when known.
     */
    private function finishWithError(mixed $job, mixed $exception, bool $wasReleased): void
    {
        $this->safely("a failed job", function () use ($job, $exception, $wasReleased): void {
            $entry = $this->recorder->openEntry("job", $job);

            if ($entry === null) {
                return;
            }

            $entry->status = "error";
            $this->recorder->markUnhandled($entry, $exception, true);

            if ($wasReleased) {
                $entry->props["released"] = true;
            }

            $this->recorder->finish($entry);
        });
    }
}
