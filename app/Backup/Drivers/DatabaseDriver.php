<?php

namespace App\Backup\Drivers;

/**
 * One implementation per supported engine (MySqlDriver, MariaDbDriver,
 * PostgresDriver), selected by App\Backup\DriverFactory from DB_CONNECTION.
 *
 * "target" below always means the project database this tool backs up and
 * restores (config/database.php's `target`/`target_root` connections),
 * never the tool's own SQLite storage.
 */
interface DatabaseDriver
{
    /**
     * The server's reported version string, e.g. "8.4.6" or "11.8.3-MariaDB".
     * Used for display and, in DriverFactory, to tell a MySQL 8 server
     * apart from a MariaDB one when DB_CONNECTION alone is ambiguous.
     */
    public function serverVersion(): string;

    public function databaseExists(string $database): bool;

    /** Size of $database on disk, in bytes; null if it doesn't exist or can't be determined. */
    public function databaseSize(string $database): ?int;

    /**
     * Creates $database with the same charset/collation (MySQL/MariaDB) or
     * owner (PostgreSQL) as $like, so a dump can be imported into it
     * without surprises. Throws if $database already exists.
     */
    public function createDatabase(string $database, string $like): void;

    /** Throws if $database doesn't exist. */
    public function dropDatabase(string $database): void;

    /**
     * Dumps $database (schema + data, no CREATE DATABASE/USE statements)
     * as plain SQL to the local file $outputPath.
     */
    public function dump(string $database, string $outputPath): void;

    /**
     * Streams the plain SQL file $sqlFilePath into $database. Aborts on
     * the first SQL error instead of continuing past it.
     */
    public function import(string $database, string $sqlFilePath): void;
}
