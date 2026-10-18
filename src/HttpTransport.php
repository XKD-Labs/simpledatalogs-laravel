<?php

namespace SimpleDataLogs\Laravel;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;

/**
 * The real transport: one Guzzle client per process, so every batch of a ship run reuses one keep-alive connection.
 * The connection closes when the scheduled command exits, about a minute later, so it is never held for long.
 */
class HttpTransport implements Transport
{
    // Seconds to open a connection, and for a whole request; a slow ingest never stalls the shipper for long.
    private const CONNECT_TIMEOUT_SECONDS = 3;
    private const REQUEST_TIMEOUT_SECONDS = 10;

    /**
     * The client every send of this process shares.
     */
    private ClientInterface $client;

    /**
     * Keeps the endpoint and the key; a client can be passed in for tests.
     */
    public function __construct(
        private string $ingestUrl,
        private string $ingestKey,
        ?ClientInterface $client = null,
    ) {
        $this->client = $client ?? new Client([
            RequestOptions::CONNECT_TIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            RequestOptions::TIMEOUT => self::REQUEST_TIMEOUT_SECONDS,
            RequestOptions::HTTP_ERRORS => false,
        ]);
    }

    /**
     * Posts the body gzipped; any exception is a network error (status 0), so the batch stays in the outbox.
     *
     * @return array{status: int, retry_after: int|null}
     */
    public function send(string $ndjsonBody): array
    {
        try {
            $response = $this->client->request("POST", $this->ingestUrl, [
                RequestOptions::HEADERS => [
                    "Authorization" => "Bearer " . $this->ingestKey,
                    "Content-Type" => "application/x-ndjson",
                    "Content-Encoding" => "gzip",
                    "User-Agent" => "simpledatalogs-laravel/0.2.0",
                ],
                RequestOptions::BODY => gzencode($ndjsonBody),
            ]);

            $retryAfter = $response->getHeaderLine("Retry-After");

            return ["status" => $response->getStatusCode(), "retry_after" => is_numeric($retryAfter) ? (int) $retryAfter : null];
        } catch (\Throwable) {
            return ["status" => 0, "retry_after" => null];
        }
    }
}
