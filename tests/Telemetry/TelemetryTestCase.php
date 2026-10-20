<?php

namespace SimpleDataLogs\Laravel\Tests\Telemetry;

use SimpleDataLogs\Laravel\Outbox;
use SimpleDataLogs\Laravel\Tests\TestCase;

/**
 * A Laravel app with telemetry on, every root sent in detail, an in-memory database, and helpers that read bundles back from the outbox.
 */
abstract class TelemetryTestCase extends TestCase
{
    /**
     * Turns detail on for every root and gives the app an in-memory SQLite database.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app["config"]->set("simpledatalogs.telemetry.detail_every", 1);
        $app["config"]->set("simpledatalogs.release", "2026.10.3");
        $app["config"]->set("database.default", "testing");
        $app["config"]->set("database.connections.testing", ["driver" => "sqlite", "database" => ":memory:", "prefix" => ""]);
    }

    /**
     * Every line waiting in the outbox, decoded, in the order written.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function outboxLines(): array
    {
        $outbox = $this->app->make(Outbox::class);
        $lines = [];

        foreach (glob($this->outboxPath . "/pending/*.ndjson") ?: [] as $pendingPath) {
            foreach ($outbox->lines($pendingPath) as $line) {
                $lines[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            }
        }

        return $lines;
    }

    /**
     * The raw outbox text, for asserting what never appears in it.
     */
    protected function outboxText(): string
    {
        return implode("\n", array_map(fn (array $line): string => json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $this->outboxLines()));
    }

    /**
     * The telemetry lines of one kind (request, job, task, command, exception).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function bundles(string $kind): array
    {
        return array_values(array_filter($this->outboxLines(), fn (array $line): bool => ($line["kind"] ?? null) === $kind));
    }

    /**
     * The only bundle of a kind, failing when there isn't exactly one.
     *
     * @return array<string, mixed>
     */
    protected function onlyBundle(string $kind): array
    {
        $bundles = $this->bundles($kind);
        $this->assertCount(1, $bundles, "expected one {$kind} bundle, got " . count($bundles));

        return $bundles[0];
    }

    /**
     * A bundle's children of one kind.
     *
     * @param array<string, mixed> $bundle
     * @return array<int, array<string, mixed>>
     */
    protected function children(array $bundle, string $childKind): array
    {
        return array_values(array_filter($bundle["spans"] ?? [], fn (array $child): bool => $child["kind"] === $childKind));
    }
}
