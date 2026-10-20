<?php

namespace SimpleDataLogs\Laravel\Tests\Telemetry;

use SimpleDataLogs\Laravel\Telemetry\Span;
use SimpleDataLogs\Laravel\Telemetry\Entry;
use SimpleDataLogs\Laravel\Telemetry\Normalizer;

use PHPUnit\Framework\TestCase;

/**
 * The wire caps: 60 children with "(other)" folds, 100 props, name lengths, the 64 KB guard, dropped keys and masking.
 */
class NormalizerTest extends TestCase
{
    /**
     * A normalizer for a fake app that drops the `queries` and `secret` keys.
     */
    private function normalizer(): Normalizer
    {
        return new Normalizer("/srv/shop", "production", "2026.10.3", ["queries", "secret"]);
    }

    /**
     * An ended request entry with the given children.
     *
     * @param array<int, Span> $spans
     */
    private function entry(array $spans = []): Entry
    {
        $entry = new Entry("request", "GET /orders", microtime(true), str_repeat("ab", 16));
        $entry->durationMs = 120.0;

        foreach ($spans as $spanIndex => $span) {
            $entry->spans["child-{$spanIndex}"] = $span;
        }

        return $entry;
    }

    public function test_children_over_sixty_fold_into_one_other_child_per_kind_keeping_counts_and_time(): void
    {
        $spans = [];

        foreach (range(1, 70) as $queryNumber) {
            $spans[] = new Span("query", "select {$queryNumber}", $queryNumber, (float) $queryNumber);
        }

        foreach (range(1, 10) as $callNumber) {
            $spans[] = new Span("http", "GET host{$callNumber}.test", 100 + $callNumber, 0.5);
        }

        $children = $this->normalizer()->bundle($this->entry($spans), true)["spans"];

        $this->assertCount(Normalizer::MAX_CHILDREN, $children);

        $otherChildren = array_values(array_filter($children, fn (array $child): bool => $child["name"] === Normalizer::OTHER_NAME));
        $this->assertSame(["query", "http"], array_column($otherChildren, "kind"));

        // Nothing is lost: every run is still counted somewhere.
        $this->assertSame(80, array_sum(array_column($children, "count")));
        $this->assertEqualsWithDelta(array_sum(range(1, 70)) + 5.0, array_sum(array_column($children, "duration_ms")), 0.001);

        // The slowest queries are the ones kept by name, in start order.
        $this->assertContains("select 70", array_column($children, "name"));
        $startOffsets = array_column($children, "start_ms");
        $sortedOffsets = $startOffsets;
        sort($sortedOffsets);
        $this->assertSame($sortedOffsets, $startOffsets);
    }

    public function test_props_are_scalar_capped_at_one_hundred_and_dropped_keys_never_leave_even_on_children(): void
    {
        $entry = $this->entry([new Span("span", "render", 1.0, 2.0, "ok", ["secret" => "zz-hidden", "pages" => 3, "nested" => ["a" => 1]])]);

        foreach (range(1, 150) as $propNumber) {
            $entry->props["prop_{$propNumber}"] = $propNumber;
        }

        $entry->props["queries"] = "select * from users where email = 'a@b.test'";
        $bundle = $this->normalizer()->bundle($entry, true);

        $this->assertCount(Normalizer::MAX_PROPS, $bundle["props"]);
        $this->assertArrayNotHasKey("queries", $bundle["props"]);
        $this->assertSame(["pages" => 3], $bundle["spans"][0]["props"]);
    }

    public function test_long_names_are_cut_and_marked(): void
    {
        $entry = $this->entry([
            new Span("query", "select " . str_repeat("x", 3000), 1.0, 1.0),
            new Span("span", str_repeat("n", 600), 2.0, 1.0),
        ]);
        $entry->name = "GET /" . str_repeat("r", 700);

        $bundle = $this->normalizer()->bundle($entry, true);

        $this->assertSame(Normalizer::MAX_NAME_BYTES, strlen($bundle["name"]));
        $this->assertTrue($bundle["name_truncated"]);
        $this->assertSame(Normalizer::MAX_SQL_BYTES, strlen($bundle["spans"][0]["name"]));
        $this->assertTrue($bundle["spans"][0]["name_truncated"]);
        $this->assertSame(Normalizer::MAX_NAME_BYTES, strlen($bundle["spans"][1]["name"]));
    }

    public function test_a_bundle_over_64_kilobytes_drops_its_children_but_keeps_the_summary(): void
    {
        $spans = array_map(fn (int $queryNumber): Span => new Span("query", "select {$queryNumber} " . str_repeat("c", 2000), $queryNumber, 1.0), range(1, 60));
        $entry = $this->entry($spans);
        $entry->summary = ["queries" => ["count" => 60, "duration_ms" => 60.0]];

        $bundleJson = $this->normalizer()->encodeBundle($entry, true);
        $bundle = json_decode($bundleJson, true);

        $this->assertLessThanOrEqual(Normalizer::MAX_BUNDLE_BYTES, strlen($bundleJson));
        $this->assertArrayNotHasKey("spans", $bundle);
        $this->assertTrue($bundle["props"]["bundle_trimmed"]);
        $this->assertSame(60, $bundle["summary"]["queries"]["count"]);
    }

    public function test_a_summary_only_bundle_has_no_children(): void
    {
        $entry = $this->entry([new Span("query", "select 1", 1.0, 1.0)]);
        $entry->summary = ["queries" => ["count" => 1, "duration_ms" => 1.0]];

        $bundle = $this->normalizer()->bundle($entry, false);

        $this->assertArrayNotHasKey("spans", $bundle);
        $this->assertSame(["count" => 1, "duration_ms" => 1.0], $bundle["summary"]["queries"]);
    }

    public function test_sql_literals_are_masked_and_identifiers_kept(): void
    {
        $this->assertSame("select * from users where id = ?", Normalizer::maskSqlLiterals("select * from users where id = ?"));
        $this->assertSame("select * from users where email = ? and age > ?", Normalizer::maskSqlLiterals("select * from users where email = 'a@b.test' and age > 30"));
        $this->assertSame("select * from t2 where col1 = ? limit ?", Normalizer::maskSqlLiterals("select * from t2 where col1 = 'it''s' limit 10"));
        $this->assertSame("select \"table_2\".\"col3\" from \"table_2\" where x = \$1", Normalizer::maskSqlLiterals("select \"table_2\".\"col3\" from \"table_2\" where x = \$1"));
    }

    public function test_cache_keys_become_patterns(): void
    {
        $this->assertSame("user:*:profile", Normalizer::maskCacheKey("user:4242:profile"));
        $this->assertSame("session:*", Normalizer::maskCacheKey("session:9f86d081884c7d659a2feaa0c55ad015"));
        $this->assertSame("order:*", Normalizer::maskCacheKey("order:3f2504e0-4f89-11d3-9a0c-0305e82c3301"));
        $this->assertSame("avatar:*", Normalizer::maskCacheKey("avatar:Zm9vYmFyYmF6cXV4cXV1eGNvcmdl"));
        $this->assertSame("settings", Normalizer::maskCacheKey("settings"));
    }
}
