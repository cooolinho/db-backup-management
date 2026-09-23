<?php

namespace App\Backup\Drivers;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\DbDumper\Databases\MariaDb as MariaDbDumper;
use Spatie\DbDumper\Exceptions\DumpFailed;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Shared implementation for MySqlDriver and MariaDbDriver.
 *
 * Debian ships no true Oracle MySQL client, and mariadb-dump/mariadb are
 * wire-compatible with MySQL 8.x for the plain dump/restore operations
 * this tool performs, so both drivers shell out to the same mariadb-client
 * binaries (see the Dockerfile). The two driver classes exist as separate,
 * near-empty subclasses so genuinely engine-specific behaviour has
 * somewhere to go later without reshaping the DriverFactory contract.
 */
abstract class AbstractMySqlFamilyDriver implements DatabaseDriver
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
            'select 1 as x from information_schema.schemata where schema_name = ?',
            [$database]
        );
    }

    public function databaseSize(string $database): ?int
    {
        if (! $this->databaseExists($database)) {
            return null;
        }

        $row = $this->root()->selectOne(
            'select sum(data_length + index_length) as bytes
             from information_schema.tables where table_schema = ?',
            [$database]
        );

        return $row?->bytes !== null ? (int) $row->bytes : 0;
    }

    public function createDatabase(string $database, string $like): void
    {
        if ($this->databaseExists($database)) {
            throw new RuntimeException("Database [{$database}] already exists.");
        }

        $reference = $this->root()->selectOne(
            'select default_character_set_name as charset, default_collation_name as collation
             from information_schema.schemata where schema_name = ?',
            [$like]
        );

        if (! $reference) {
            throw new RuntimeException("Reference database [{$like}] does not exist.");
        }

        $this->root()->statement(sprintf(
            'create database %s character set `%s` collate `%s`',
            $this->quoteIdentifier($database),
            $reference->charset,
            $reference->collation,
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

        $dumper = MariaDbDumper::create()
            ->setHost($config['host'])
            ->setPort((int) $config['port'])
            ->setDbName($database)
            ->setUserName($config['username'])
            ->setPassword($config['password'])
            ->useSingleTransaction()
            ->useQuick()
            ->skipLockTables()
            ->addExtraOption('--routines')
            ->addExtraOption('--triggers')
            ->addExtraOption('--events')
            ->addExtraOption('--hex-blob')
            ->addExtraOption('--no-tablespaces');

        if (! empty($config['unix_socket'])) {
            $dumper->setSocket($config['unix_socket']);
        }

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

        $config = $this->connectionConfig($this->rootConnection);
        $credentialsFile = $this->writeCredentialsFile($config);
        $handle = fopen($sqlFilePath, 'r');

        if ($handle === false) {
            @unlink($credentialsFile);

            throw new RuntimeException("Cannot read dump file [{$sqlFilePath}].");
        }

        try {
            $process = new Process([
                'mariadb',
                "--defaults-extra-file={$credentialsFile}",
                '--host='.$config['host'],
                '--port='.$config['port'],
                $database,
            ]);
            $process->setInput($handle);
            $process->setTimeout((int) config('backup.job_timeout'));
            $process->run();

            if (! $process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }
        } finally {
            fclose($handle);
            @unlink($credentialsFile);
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

    private function writeCredentialsFile(array $config): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dbcred_');

        file_put_contents($path, sprintf(
            "[client]\nuser=%s\npassword=%s\n",
            $config['username'],
            $config['password'],
        ));
        chmod($path, 0600);

        return $path;
    }

    private function quoteIdentifier(string $identifier): string
    {
        // Our own database/archive names never contain backticks; this is
        // a defensive guard, not a general-purpose SQL identifier quoter.
        return '`'.str_replace('`', '``', $identifier).'`';
    }
}
