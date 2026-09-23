<?php

namespace App\Backup;

use Carbon\CarbonImmutable;

/**
 * Names for the two kinds of database a restore creates:
 * - a temporary import target: {db}_restore_tmp (truncated if needed)
 * - an archive of what "live" held before a restore/swap-back:
 *   {db}_{Ymd}_{Hi}_v{n} (truncated if needed), n being one more than the
 *   highest existing version for that database on the server
 *
 * Truncation keeps names within 63 characters - safe for both MySQL/
 * MariaDB (limit 64) and PostgreSQL (limit 63).
 *
 * isArchiveOf() is the server-side guard that keeps SwapArchiveJob/
 * DropArchiveJob from ever acting on the live database or an unrelated
 * one: it re-derives the truncated base itself and only recognizes a name
 * that could actually have come out of next() for that exact database.
 */
class ArchiveNamer
{
    private const MAX_LENGTH = 63;

    private const TMP_SUFFIX = '_restore_tmp';

    // "_YYYYMMDD_HHMM_v" (17 chars) + up to 4 digits of version - versions
    // realistically never reach 5 digits, but the budget is generous on
    // purpose rather than exact.
    private const ARCHIVE_SUFFIX_BUDGET = 21;

    public function tmpName(string $database): string
    {
        $base = substr($database, 0, self::MAX_LENGTH - strlen(self::TMP_SUFFIX));

        return $base.self::TMP_SUFFIX;
    }

    /**
     * @param list<string> $existingNames Every database name that currently exists on the server
     *   (or at least every one starting with $database - the caller decides how it fetches these,
     *   typically via DatabaseDriver::listDatabases()).
     */
    public function next(string $database, array $existingNames): string
    {
        $version = $this->highestVersion($database, $existingNames) + 1;
        $timestamp = CarbonImmutable::now()->format('Ymd_Hi');

        return $this->truncatedBase($database).'_'.$timestamp.'_v'.$version;
    }

    public function isArchiveOf(string $database, string $candidate): bool
    {
        return (bool) preg_match($this->pattern($database), $candidate);
    }

    /** @return array{timestamp: CarbonImmutable, version: int}|null */
    public function parse(string $database, string $candidate): ?array
    {
        if (! preg_match($this->pattern($database), $candidate, $matches)) {
            return null;
        }

        return [
            'timestamp' => CarbonImmutable::createFromFormat('Ymd_Hi', $matches['ts']),
            'version' => (int) $matches['version'],
        ];
    }

    private function highestVersion(string $database, array $existingNames): int
    {
        $highest = 0;

        foreach ($existingNames as $name) {
            $parsed = $this->parse($database, $name);

            if ($parsed !== null) {
                $highest = max($highest, $parsed['version']);
            }
        }

        return $highest;
    }

    private function truncatedBase(string $database): string
    {
        return substr($database, 0, self::MAX_LENGTH - self::ARCHIVE_SUFFIX_BUDGET);
    }

    private function pattern(string $database): string
    {
        $base = preg_quote($this->truncatedBase($database), '/');

        return "/^{$base}_(?<ts>\d{8}_\d{4})_v(?<version>\d+)$/";
    }
}
