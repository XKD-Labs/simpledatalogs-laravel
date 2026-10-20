<?php

namespace SimpleDataLogs\Laravel\Telemetry;

/**
 * Finds the release every bundle carries: the configured one, else the deployed git SHA, else APP_VERSION.
 */
final class Release
{
    // A full or abbreviated git commit SHA.
    private const SHA_PATTERN = "/^[0-9a-f]{7,40}$/";

    // Releases longer than this are cut.
    private const MAX_RELEASE_BYTES = 128;

    /**
     * The release from config, the git SHA file, or the app version; null when none is known.
     */
    public static function resolve(mixed $configuredRelease, string $basePath, mixed $appVersion): ?string
    {
        $basePath = rtrim($basePath, "/");

        foreach ([$configuredRelease, fn (): ?string => self::gitSha($basePath), $appVersion] as $candidate) {
            $release = $candidate instanceof \Closure ? $candidate() : $candidate;

            if (is_scalar($release) && trim((string) $release) !== "") {
                return mb_strcut(trim((string) $release), 0, self::MAX_RELEASE_BYTES);
            }
        }

        return null;
    }

    /**
     * The deployed commit: a deploy tool's REVISION file first, then the checkout's HEAD.
     */
    private static function gitSha(string $basePath): ?string
    {
        $revisionSha = self::sha(@file_get_contents($basePath . "/REVISION"));

        if ($revisionSha !== null) {
            return $revisionSha;
        }

        // .git is a folder in a plain checkout; worktrees and submodules make it a file, skipped here.
        $headText = is_file($basePath . "/.git/HEAD") ? @file_get_contents($basePath . "/.git/HEAD") : false;

        if ($headText === false) {
            return null;
        }

        // A detached HEAD holds the SHA itself.
        $headText = trim($headText);

        if (! str_starts_with($headText, "ref: ")) {
            return self::sha($headText);
        }

        // A branch: its loose ref file, else its line in packed-refs.
        $branchRef = substr($headText, 5);
        $looseSha = self::sha(@file_get_contents($basePath . "/.git/" . $branchRef));

        if ($looseSha !== null) {
            return $looseSha;
        }

        foreach (@file($basePath . "/.git/packed-refs", FILE_IGNORE_NEW_LINES) ?: [] as $packedLine) {
            $packedParts = explode(" ", $packedLine, 2);

            if (count($packedParts) === 2 && $packedParts[1] === $branchRef) {
                return self::sha($packedParts[0]);
            }
        }

        return null;
    }

    /**
     * The trimmed text when it is a SHA, lowercased; null otherwise.
     */
    private static function sha(string|false $text): ?string
    {
        if ($text === false) {
            return null;
        }

        $candidateSha = strtolower(trim($text));

        return preg_match(self::SHA_PATTERN, $candidateSha) === 1 ? $candidateSha : null;
    }
}
