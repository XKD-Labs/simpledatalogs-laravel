<?php

namespace SimpleDataLogs\Laravel\Tests;

use SimpleDataLogs\Laravel\Transport;

/**
 * A transport that answers with seeded random ingest API statuses and Retry-After values, recording each body with its answer.
 */
class RandomAnswerTransport implements Transport
{
    /**
     * When true, every body is accepted.
     */
    public bool $isAcceptingAll = false;

    /**
     * Every body sent with the status it got, in order.
     *
     * @var array<int, array{body: string, status: int}>
     */
    public array $answeredBodies = [];

    /**
     * Records the body and answers it, from mt_rand so a seed replays the same answers.
     *
     * @return array{status: int, retry_after: int|null}
     */
    public function send(string $ndjsonBody): array
    {
        $statuses = [202, 202, 202, 200, 401, 408, 413, 415, 429, 500, 503, 0];
        $retryAfters = [null, null, 0, 1, 2, 5, 30, 3600];

        $answer = $this->isAcceptingAll
            ? ["status" => 202, "retry_after" => null]
            : ["status" => $statuses[mt_rand(0, count($statuses) - 1)], "retry_after" => $retryAfters[mt_rand(0, count($retryAfters) - 1)]];

        $this->answeredBodies[] = ["body" => $ndjsonBody, "status" => $answer["status"]];

        return $answer;
    }

    /**
     * The "n" of every line in bodies answered with one of the statuses.
     *
     * @param array<int, int> $statuses
     * @return array<int, int>
     */
    public function lineNumbersAnswered(array $statuses): array
    {
        $answered = array_filter($this->answeredBodies, fn (array $answeredBody): bool => in_array($answeredBody["status"], $statuses, true));

        return array_merge([], ...array_map(fn (array $answeredBody): array => $this->lineNumbers($answeredBody["body"]), $answered));
    }

    /**
     * The "n" of every line that was alone in a body answered with the status.
     *
     * @return array<int, int>
     */
    public function loneLineNumbersAnswered(int $status): array
    {
        $loneBodies = array_filter(
            $this->answeredBodies,
            fn (array $answeredBody): bool => $answeredBody["status"] === $status && count($this->lineNumbers($answeredBody["body"])) === 1,
        );

        return array_merge([], ...array_map(fn (array $answeredBody): array => $this->lineNumbers($answeredBody["body"]), $loneBodies));
    }

    /**
     * The "n" of each line in one body.
     *
     * @return array<int, int>
     */
    private function lineNumbers(string $ndjsonBody): array
    {
        return array_map(fn (string $line): int => json_decode($line, true)["n"], explode("\n", trim($ndjsonBody)));
    }
}
