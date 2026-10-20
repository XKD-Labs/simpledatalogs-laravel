<?php

namespace SimpleDataLogs\Laravel\Tests\Telemetry;

use SimpleDataLogs\Laravel\Telemetry\Release;

use PHPUnit\Framework\TestCase;

/**
 * The release: config first, then the deployed git SHA (REVISION file, .git HEAD, packed refs), then APP_VERSION.
 */
class ReleaseTest extends TestCase
{
    // A throwaway app folder per test.
    private string $appPath;

    /**
     * Creates the app folder.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->appPath = sys_get_temp_dir() . "/sdl-release-" . bin2hex(random_bytes(6));
        mkdir($this->appPath . "/.git/refs/heads", 0775, true);
    }

    /**
     * Removes the app folder.
     */
    protected function tearDown(): void
    {
        exec("rm -rf " . escapeshellarg($this->appPath));

        parent::tearDown();
    }

    public function test_the_configured_release_wins(): void
    {
        file_put_contents($this->appPath . "/REVISION", str_repeat("a", 40));

        $this->assertSame("2026.10.3", Release::resolve(" 2026.10.3 ", $this->appPath, "9.9.9"));
    }

    public function test_a_revision_file_comes_before_the_checkout(): void
    {
        file_put_contents($this->appPath . "/REVISION", strtoupper(str_repeat("ab", 20)) . "\n");
        file_put_contents($this->appPath . "/.git/HEAD", str_repeat("c", 40));

        $this->assertSame(str_repeat("ab", 20), Release::resolve(null, $this->appPath, null));
    }

    public function test_the_checkouts_branch_sha_is_read_from_its_ref_or_packed_refs(): void
    {
        file_put_contents($this->appPath . "/.git/HEAD", "ref: refs/heads/main\n");
        file_put_contents($this->appPath . "/.git/refs/heads/main", str_repeat("d", 40) . "\n");

        $this->assertSame(str_repeat("d", 40), Release::resolve("", $this->appPath, null));

        unlink($this->appPath . "/.git/refs/heads/main");
        file_put_contents($this->appPath . "/.git/packed-refs", "# pack-refs with: peeled\n" . str_repeat("e", 40) . " refs/heads/main\n");

        $this->assertSame(str_repeat("e", 40), Release::resolve(null, $this->appPath, null));
    }

    public function test_app_version_is_the_last_fallback_and_nothing_gives_null(): void
    {
        $this->assertSame("3.2.0", Release::resolve(null, $this->appPath, "3.2.0"));
        $this->assertNull(Release::resolve(null, $this->appPath, null));
    }
}
