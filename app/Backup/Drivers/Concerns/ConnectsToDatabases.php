<?php

namespace App\Backup\Drivers\Concerns;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Some operations (PostgreSQL: renaming/dropping a database, or reading
 * its own information_schema) need a connection to a database OTHER than
 * whichever one config/database.php's target/target_root are currently
 * pointed at. This registers a throwaway root-credentialed connection to
 * an arbitrary database name on demand, without touching the static
 * 'target'/'target_root' config.
 *
 * Expects the using class to already have connectionConfig(string): array
 * (both AbstractMySqlFamilyDriver and PostgresDriver do).
 */
trait ConnectsToDatabases
{
    protected function connectionFor(string $database): Connection
    {
        $name = $this->dynamicConnectionName($database);
        $config = $this->connectionConfig($this->rootConnection);
        $config['database'] = $database;

        config(["database.connections.{$name}" => $config]);

        return DB::connection($name);
    }

    protected function purgeConnectionFor(string $database): void
    {
        DB::purge($this->dynamicConnectionName($database));
    }

    private function dynamicConnectionName(string $database): string
    {
        return 'dynamic_'.md5($database);
    }
}
