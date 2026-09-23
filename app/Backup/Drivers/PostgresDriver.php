<?php

namespace App\Backup\Drivers;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\DbDumper\Databases\PostgreSql as PostgresDumper;
use Spatie\DbDumper\Exceptions\DumpFailed;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * PostgreSQL 14-18 targets (pg_dump 18 from the PGDG repo talks to older
 * servers fine too). Read/admin queries here run through the existing
 * `target_root` connection (already connected to the live database, which
 * is enough for querying cluster-wide catalogs and creating/dropping
 * *other* database names); an actual RENAME needs a connection to a
 * database other than the one being renamed, which is added alongside
 * swap() in a later phase.
 */
class PostgresDriver implements DatabaseDriver
{
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

        $dumper = PostgresDumper::create()
            ->setHost($config['host'])
            ->setPort((int) $config['port'])
            ->setDbName($database)
            ->setUserName($config['username'])
            ->setPassword($config['password'])
            ->addExtraOption('--format=plain')
            ->addExtraOption('--no-owner')
            ->addExtraOption('--no-privileges');

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
        $appUsername = $this->connectionConfig($this->connection)['username'];

        // Connect as root but SET ROLE to the app user first (one session,
        // -c then -f run in order), so every imported object ends up
        // owned by the app user instead of root/postgres.
        $process = new Process([
            'psql',
            '--host='.$rootConfig['host'],
            '--port='.$rootConfig['port'],
            '--username='.$rootConfig['username'],
            '--dbname='.$database,
            '--set=ON_ERROR_STOP=1',
            '--quiet',
            '--command=SET ROLE '.$this->quoteIdentifier($appUsername).';',
            '--file='.$sqlFilePath,
        ], null, ['PGPASSWORD' => $rootConfig['password']]);
        $process->setTimeout((int) config('backup.job_timeout'));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
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
