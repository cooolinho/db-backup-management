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
        return match ($driver ?? config('backup.driver')) {
            'mysql' => new MySqlDriver(),
            'mariadb' => new MariaDbDriver(),
            'pgsql' => new PostgresDriver(),
            default => throw new InvalidArgumentException(
                "Unsupported DB_CONNECTION [{$driver}]. Expected one of: mysql, mariadb, pgsql."
            ),
        };
    }
}
