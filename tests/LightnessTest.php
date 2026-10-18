<?php

namespace SimpleDataLogs\Laravel\Tests;

use SimpleDataLogs\Laravel\Outbox;
use SimpleDataLogs\Laravel\Shipper;
use SimpleDataLogs\Laravel\Transport;
use SimpleDataLogs\Laravel\HttpTransport;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;

/**
 * The package stays out of the request's way: logging only appends to a local file, and shipping reuses one client.
 */
class LightnessTest extends TestCase
{
    public function test_logging_never_touches_the_network_and_costs_well_under_a_millisecond_a_line(): void
    {
        $fakeTransport = new FakeTransport();
        $this->app->instance(Transport::class, $fakeTransport);

        // 2,000 lines, as a busy request burst would log them.
        $startedAt = hrtime(true);

        foreach (range(1, 2000) as $lineNumber) {
            Log::channel("partner_api")->debug("/catalog/products {$lineNumber} 1", ["request_number" => $lineNumber]);
        }

        $elapsedMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;

        // Nothing is sent while the app logs; the ship command does that later, out of the request.
        $this->assertSame([], $fakeTransport->sentBodies);

        $outbox = $this->app->make(Outbox::class);
        $queuedCount = 0;

        foreach (glob($this->outboxPath . "/pending/*.ndjson") ?: [] as $pendingPath) {
            $queuedCount += count($outbox->lines($pendingPath));
        }

        $this->assertSame(2000, $queuedCount);

        // Generous for a shared CI box: under 1 ms a line on average, Laravel's own file logging included.
        $this->assertLessThan(2000, $elapsedMilliseconds, "2,000 lines took {$elapsedMilliseconds} ms");
    }

    public function test_one_client_serves_every_batch_of_a_ship_run(): void
    {
        // A recording handler stands in for the network; the transport must keep using the same client.
        $mockHandler = new MockHandler(array_fill(0, 3, new Response(202, [], "{}")));
        $sentRequests = [];
        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(function (callable $nextHandler) use (&$sentRequests) {
            return function ($request, array $options) use ($nextHandler, &$sentRequests) {
                $sentRequests[] = $request;

                return $nextHandler($request, $options);
            };
        });

        $transport = new HttpTransport("http://ingest.test/v1/ingest", "sdl_sec_test", new Client(["handler" => $handlerStack]));
        $this->app->instance(Transport::class, $transport);
        $this->app->forgetInstance(Shipper::class);

        // Three batches' worth of lines, shipped in one run.
        foreach (range(1, 120) as $lineNumber) {
            Log::channel("partner_api")->debug("/catalog/products {$lineNumber} 1");
        }

        $this->assertSame(120, $this->app->make(Shipper::class)->ship(time() + 30));
        $this->assertCount(3, $sentRequests);

        foreach ($sentRequests as $sentRequest) {
            $this->assertSame("gzip", $sentRequest->getHeaderLine("Content-Encoding"));
            $this->assertSame("Bearer sdl_sec_test", $sentRequest->getHeaderLine("Authorization"));
        }
    }

    public function test_the_package_needs_nothing_beyond_laravel_monolog_and_guzzle(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . "/../composer.json"), true);

        $this->assertSame(
            ["guzzlehttp/guzzle", "illuminate/console", "illuminate/http", "illuminate/support", "monolog/monolog", "php"],
            collect(array_keys($composer["require"]))->sort()->values()->all(),
        );
    }
}
