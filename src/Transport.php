<?php

namespace SimpleDataLogs\Laravel;

/**
 * Sends one NDJSON body to ingest; the HTTP client lives behind this, so tests use a fake.
 */
interface Transport
{
    /**
     * Posts one body and returns the HTTP status (0 for a network error) and any Retry-After seconds.
     *
     * @return array{status: int, retry_after: int|null}
     */
    public function send(string $ndjsonBody): array;
}
