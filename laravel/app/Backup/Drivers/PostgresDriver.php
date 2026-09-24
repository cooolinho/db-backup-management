<?php

namespace App\Backup\Drivers;

use App\Backup\Drivers\Concerns\ConnectsToDatabases;
use App\Backup\Drivers\Support\PostgresOwnershipFixer;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\DbDumper\Databases\PostgreSql as PostgresDumper;
use Spatie\DbDumper\Exceptions\DumpFailed;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * PostgreSQL 14-18 targets (pg_dump 18 from the PGDG repo talks to older
 * servers fine too). Plain read/admin queries run through the existing
 * target_root connection (connected to the live database, which is enough
 * for querying cluster-wide catalogs and creating/dropping *other*
 * database names); renaming/dropping the currently-connected database, or
 * reading a database's own information_schema, needs a connection to a
 * different database - see ConnectsToDatabases.
 */
class PostgresDriver implements DatabaseDriver
{
    use ConnectsToDatabases;

    /** The database every Postgres server ships, used as a connection target for admin operations on other databases. */
    private const MAINTENANCE_DATABASE = 'postgres';

    public function __construct(
        protected readonly string $connection = 'target',
        protected readonly string $rootConnection = 'target_root',
    ) {}

    public function serverVersion(): string
    {
        return $this->root()->selectOne('select version() as v')->v;
    }

    public function databaseExists(string $database): bool
    {
        return (bool) $this->root()->selectOne(
            'select 1 as x from pg_database where datname = ?',
            [$database]
        );
    }

    public function databaseSize(string $database): ?int
    {
        if (! $this->databaseExists($database)) {
            return null;
        }

        $row = $this->root()->selectOne('select pg_database_size(?) as bytes', [$database]);

        return $row !== null ? (int) $row->bytes : null;
    }

    public function createDatabase(string $database, string $like): void
    {
        if ($this->databaseExists($database)) {
            throw new RuntimeException("Database [{$database}] already exists.");
        }

        $reference = $this->root()->selectOne(
            'select pg_encoding_to_char(encoding) as encoding, datcollate, datctype,
                    pg_get_userbyid(datdba) as owner
             from pg_database where datname = ?',
            [$like]
        );

        if (! $reference) {
            throw new RuntimeException("Reference database [{$like}] does not exist.");
        }

        $this->root()->statement(sprintf(
            'create database %s with owner %s encoding %s lc_collate %s lc_ctype %s template template0',
            $this->quoteIdentifier($database),
            $this->quoteIdentifier($reference->owner),
            $this->quoteLiteral($reference->encoding),
            $this->quoteLiteral($reference->datcollate),
            $this->quoteLiteral($reference->datctype),
        ));
    }

    public function dropDatabase(string $database): void
    {
        if (! $this->databaseExists($database)) {
            throw new RuntimeException("Database [{$database}] does not exist.");
        }

        $this->root()->statement('drop database '.$this->quoteIdentifier($database));
    }

    public function dump(string $database, string $outputPath): void
    {
        $config = $this->connectionConfig($this->connection);

        // Deliberately kept WITH owner/privilege info (unlike a typical
        // --no-owner --no-privileges dump): imported as superuser (see
        // import()), the embedded ALTER ... OWNER TO / GRANT statements
        // restore the exact original ownership/ACLs, and fixOwnership()
        // below is then just a no-op safety net rather than the only
        // thing setting ownership at all.
        $dumper = PostgresDumper::create()
            ->setHost($config['host'])
            ->setPort((int) $config['port'])
            ->setDbName($database)
            ->setUserName($config['username'])
            ->setPassword($config['password'])
            ->addExtraOption('--format=plain');

        try {
            $dumper->dumpToFile($outputPath);
        } catch (DumpFailed $e) {
            throw new RuntimeException("Dump of [{$database}] failed: {$e->getMessage()}", previous: $e);
        }
    }

    public function import(string $database, string $sqlFilePath): void
    {
        if (! $this->databaseExists($database)) {
            throw new RuntimeException("Database [{$database}] does not exist.");
        }

        $rootConfig = $this->connectionConfig($this->rootConnection);

        // Imports as the root/superuser, not the app user: a foreign or
        // hand-edited dump (e.g. an uploaded one) may contain statements
        // (CREATE EXTENSION, ALTER ... OWNER TO, ...) the app user has no
        // privilege for. fixOwnership() below hands ownership to the app
        // user afterwards regardless of what the dump did or didn't set.
        $process = new Process([
            'psql',
            '--host='.$rootConfig['host'],
            '--port='.$rootConfig['port'],
            '--username='.$rootConfig['username'],
            '--dbname='.$database,
            '--set=ON_ERROR_STOP=1',
            '--quiet',
            '--file='.$sqlFilePath,
        ], null, ['PGPASSWORD' => $rootConfig['password']]);
        $process->setTimeout((int) config('backup.job_timeout'));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $this->fixOwnership($database, $rootConfig['username'], $this->connectionConfig($this->connection)['username']);
    }

    public function tableCount(string $database): int
    {
        $connection = $this->connectionFor($database);

        try {
            $row = $connection->selectOne(
                "select count(*) as n from information_schema.tables
                 where table_schema not in ('pg_catalog', 'information_schema')"
            );

            return (int) $row->n;
        } finally {
            $this->purgeConnectionFor($database);
        }
    }

    public function listDatabases(string $likePrefix): array
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $likePrefix);

        $rows = $this->root()->select(
            'select datname from pg_database where datname like ? order by datname',
            [$escaped.'%']
        );

        return array_map(fn ($row) => $row->datname, $rows);
    }

    public function swap(string $live, string $replacement, string $archive): void
    {
        if (! $this->databaseExists($live)) {
            throw new RuntimeException("Database [{$live}] does not exist.");
        }
        if (! $this->databaseExists($replacement)) {
            throw new RuntimeException("Database [{$replacement}] does not exist.");
        }
        if ($this->databaseExists($archive)) {
            throw new RuntimeException("Database [{$archive}] already exists.");
        }

        // Custom per-database GRANTs/settings on $live (beyond what
        // createDatabase() already copied - owner and encoding/collation)
        // would otherwise be lost when $replacement takes over its name.
        $this->transferDatabaseLevelPrivileges($live, $replacement);

        DB::purge($this->connection);
        DB::purge($this->rootConnection);

        $maintenance = $this->connectionFor(self::MAINTENANCE_DATABASE);

        try {
            foreach ([$live, $replacement] as $database) {
                $maintenance->statement(
                    'alter database '.$this->quoteIdentifier($database).' with allow_connections false'
                );
                $this->terminateBackends($maintenance, $database);
            }

            // ALTER DATABASE ... RENAME TO is transactional in PostgreSQL:
            // if the second rename fails, the first is rolled back too, so
            // $live is never left half-renamed.
            $maintenance->transaction(function () use ($maintenance, $live, $replacement, $archive) {
                $maintenance->statement(
                    'alter database '.$this->quoteIdentifier($live).' rename to '.$this->quoteIdentifier($archive)
                );
                $maintenance->statement(
                    'alter database '.$this->quoteIdentifier($replacement).' rename to '.$this->quoteIdentifier($live)
                );
            });

            $maintenance->statement('alter database '.$this->quoteIdentifier($archive).' with allow_connections true');
            $maintenance->statement('alter database '.$this->quoteIdentifier($live).' with allow_connections true');
        } catch (Throwable $e) {
            // The transaction already rolled the renames back; $live and
            // $replacement still hold their original names and content.
            $maintenance->statement('alter database '.$this->quoteIdentifier($live).' with allow_connections true');
            $maintenance->statement('alter database '.$this->quoteIdentifier($replacement).' with allow_connections true');

            throw $e;
        } finally {
            $this->purgeConnectionFor(self::MAINTENANCE_DATABASE);
            DB::purge($this->connection);
            DB::purge($this->rootConnection);
        }
    }

    protected function root(): Connection
    {
        return DB::connection($this->rootConnection);
    }

    protected function connectionConfig(string $connection): array
    {
        return config("database.connections.{$connection}");
    }

    /**
     * Hands ownership of everything in $database currently owned by
     * $fromRole (the importing superuser) to $toRole (the app user). See
     * PostgresOwnershipFixer for why this isn't just `REASSIGN OWNED BY`.
     */
    private function fixOwnership(string $database, string $fromRole, string $toRole): void
    {
        $connection = $this->connectionFor($database);

        try {
            PostgresOwnershipFixer::fix($connection, $fromRole, $toRole);
        } finally {
            $this->purgeConnectionFor($database);
        }
    }

    private function transferDatabaseLevelPrivileges(string $from, string $to): void
    {
        $this->transferDatabaseConfig($from, $to);
        $this->transferDatabaseAcl($from, $to);
    }

    /** Replays any `ALTER DATABASE ... SET x = y` / `ALTER ROLE ... IN DATABASE ... SET x = y` from $from onto $to. */
    private function transferDatabaseConfig(string $from, string $to): void
    {
        $rows = $this->root()->select(
            'select r.rolname, unnest(drs.setconfig) as entry
             from pg_db_role_setting drs
             join pg_database d on d.oid = drs.setdatabase
             left join pg_roles r on r.oid = drs.setrole
             where d.datname = ?',
            [$from]
        );

        foreach ($rows as $row) {
            [$name, $value] = explode('=', $row->entry, 2);

            $target = $row->rolname !== null
                ? 'role '.$this->quoteIdentifier($row->rolname).' in database '.$this->quoteIdentifier($to)
                : 'database '.$this->quoteIdentifier($to);

            $this->root()->statement(
                "alter {$target} set ".$this->quoteIdentifier($name).' = '.$this->quoteLiteral($value)
            );
        }
    }

    /** Replays any GRANTs on the $from database itself (not its objects) onto $to. */
    private function transferDatabaseAcl(string $from, string $to): void
    {
        $rows = $this->root()->select(
            "select
                 case when a.grantee = 0 then 'PUBLIC' else r.rolname end as grantee,
                 a.privilege_type
             from pg_database d
             cross join lateral aclexplode(d.datacl) as a
             left join pg_roles r on r.oid = a.grantee
             where d.datname = ?",
            [$from]
        );

        foreach ($rows as $row) {
            $grantee = $row->grantee === 'PUBLIC' ? 'PUBLIC' : $this->quoteIdentifier($row->grantee);

            $this->root()->statement(
                "grant {$row->privilege_type} on database ".$this->quoteIdentifier($to)." to {$grantee}"
            );
        }
    }

    private function terminateBackends(Connection $maintenance, string $database): void
    {
        $maintenance->statement(
            'select pg_terminate_backend(pid) from pg_stat_activity where datname = ? and pid <> pg_backend_pid()',
            [$database]
        );
    }

    private function quoteIdentifier(string $identifier): string
    {
        // Our own database/role names never contain double quotes; this is
        // a defensive guard, not a general-purpose SQL identifier quoter.
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function quoteLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
