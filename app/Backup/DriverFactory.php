<?php

namespace App\Backup;

use App\Backup\Drivers\DatabaseDriver;
use App\Backup\Drivers\MariaDbDriver;
use App\Backup\Drivers\MySqlDriver;
use App\Backup\Drivers\PostgresDriver;
use InvalidArgumentException;

class DriverFactory
{
    /** @param 'mysql'|'mariadb'|'pgsql' $driver */
    public function make(?string $driver = null): DatabaseDriver
    {
        // config('database.connections.target.driver') rather than
        // config('backup.driver'): both come from DB_CONNECTION in
        // production, but the connection config is the one thing that
        // actually determines which server calls go to, so it's the
        // single source of truth here rather than two settings that have
        // to be kept in sync by convention.
        return match ($driver ?? config('database.connections.target.driver')) {
            'mysql' => new MySqlDriver(),
            'mariadb' => new MariaDbDriver(),
            'pgsql' => new PostgresDriver(),
            default => throw new InvalidArgumentException(
                "Unsupported DB_CONNECTION [{$driver}]. Expected one of: mysql, mariadb, pgsql."
            ),
        };
    }
}
