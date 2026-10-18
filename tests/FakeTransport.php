<?php

namespace SimpleDataLogs\Laravel\Tests;

use SimpleDataLogs\Laravel\Transport;

/**
 * A transport that records every body and answers with scripted statuses, then 202.
 */
class FakeTransport implements Transport
{
    /**
     * Every body sent, in order.
     *
     * @var array<int, string>
     */
    public array $sentBodies = [];

    /**
     * Answers to give before falling back to 202, first one first; a Throwable is thrown instead, like a process dying mid-request.
     *
     * @param array<int, array{status: int, retry_after: int|null}|\Throwable> $scriptedAnswers
     */
    public function __construct(private array $scriptedAnswers = [])
    {
    }

    /**
     * Records the body and gives the next scripted answer.
     *
     * @return array{status: int, retry_after: int|null}
     */
    public function send(string $ndjsonBody): array
    {
        $this->sentBodies[] = $ndjsonBody;
        $nextAnswer = array_shift($this->scriptedAnswers) ?? ["status" => 202, "retry_after" => null];

        if ($nextAnswer instanceof \Throwable) {
            throw $nextAnswer;
        }

        return $nextAnswer;
    }

    /**
     * How many lines each sent body held.
     *
     * @return array<int, int>
     */
    public function linesPerBody(): array
    {
        return array_map(fn (string $sentBody): int => substr_count($sentBody, "\n"), $this->sentBodies);
    }
}
