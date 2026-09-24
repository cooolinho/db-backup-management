<?php

namespace App\Backup\Drivers;

use App\Backup\Drivers\Concerns\ConnectsToDatabases;
use App\Backup\Drivers\Support\MySqlObjectRegistry;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\DbDumper\Databases\MariaDb as MariaDbDumper;
use Spatie\DbDumper\Exceptions\DumpFailed;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Shared implementation for MySqlDriver and MariaDbDriver.
 *
 * Debian ships no true Oracle MySQL client, and mariadb-dump/mariadb are
 * wire-compatible with MySQL 8.x for the plain dump/restore operations
 * this tool performs, so both drivers shell out to the same mariadb-client
 * binaries (see docker/Dockerfile, repo root). The two driver classes exist as separate,
 * near-empty subclasses so genuinely engine-specific behaviour has
 * somewhere to go later without reshaping the DriverFactory contract.
 */
abstract class AbstractMySqlFamilyDriver implements DatabaseDriver
{
    use ConnectsToDatabases;

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
            ->addExtraOption('--no-tablespaces')
            // The target is only ever reachable over the internal Docker
            // network this container is attached to, never over the
            // public internet, so TLS buys no confidentiality here - and
            // a modern mariadb-client (11.x) refuses by default to trust
            // a MySQL/MariaDB server's self-signed certificate.
            ->addExtraOption('--skip-ssl');

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
                '--skip-ssl',
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

    public function tableCount(string $database): int
    {
        $row = $this->root()->selectOne(
            "select count(*) as n from information_schema.tables where table_schema = ? and table_type = 'BASE TABLE'",
            [$database]
        );

        return (int) $row->n;
    }

    public function listDatabases(string $likePrefix): array
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $likePrefix);

        $rows = $this->root()->select(
            'select schema_name as name from information_schema.schemata where schema_name like ? order by schema_name',
            [$escaped.'%']
        );

        return array_map(fn ($row) => $row->name, $rows);
    }

    /**
     * There is no RENAME DATABASE in MySQL/MariaDB. This instead: creates
     * $archive (copying $live's charset/collation), drops triggers in
     * $live and $replacement (the only object type that can block a
     * cross-schema RENAME TABLE - views/routines/events don't), performs
     * one atomic multi-table RENAME TABLE moving $live's tables into
     * $archive and $replacement's tables into $live, then recreates
     * views/routines/events/triggers in $live (from $replacement) and
     * $archive (from $live's originals), and finally drops the now-empty
     * $replacement. See MySqlObjectRegistry for why no reference
     * rewriting is needed for any of that.
     */
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

        $this->createDatabase($archive, like: $live);

        $liveConnection = $this->connectionFor($live);
        $replacementConnection = $this->connectionFor($replacement);

        $liveObjects = MySqlObjectRegistry::capture($liveConnection, $live);
        $replacementObjects = MySqlObjectRegistry::capture($replacementConnection, $replacement);

        MySqlObjectRegistry::dropTriggers($liveConnection, $liveObjects);
        MySqlObjectRegistry::dropTriggers($replacementConnection, $replacementObjects);

        try {
            $this->renameAllTables($live, $replacement, $archive);
        } catch (Throwable $e) {
            // The rename never took effect (or MySQL rolled the whole
            // RENAME TABLE list back - it's all-or-nothing): live and
            // replacement still hold their original tables, so restoring
            // their triggers and dropping the still-empty archive fully
            // undoes everything attempted so far.
            MySqlObjectRegistry::recreateTriggers($liveConnection, $liveObjects['triggers']);
            MySqlObjectRegistry::recreateTriggers($replacementConnection, $replacementObjects['triggers']);
            $this->dropDatabase($archive);
            $this->purgeConnectionFor($live);
            $this->purgeConnectionFor($replacement);

            throw $e;
        }

        try {
            // live now holds replacement's tables; give it replacement's
            // views/routines/events/triggers too (its own old ones are
            // stale - they'd reference whatever the new tables happen to
            // still be named, not necessarily shaped the same way).
            MySqlObjectRegistry::dropViewsRoutinesEvents($liveConnection, $liveObjects);
            MySqlObjectRegistry::recreateAll($liveConnection, $replacementObjects);

            $archiveConnection = $this->connectionFor($archive);
            MySqlObjectRegistry::recreateAll($archiveConnection, $liveObjects);
            $this->purgeConnectionFor($archive);

            $this->dropDatabase($replacement);
        } catch (Throwable $e) {
            // The table data has already moved and is safe; only the
            // schema objects (views/triggers/routines/events) are
            // incomplete at this point, so this is reported rather than
            // rolled back - undoing the table rename now would be its own
            // atomicity problem, and the underlying data was the point.
            throw new RuntimeException(
                "The table swap for [{$live}] succeeded, but recreating views/triggers/routines/events failed: ".
                "{$e->getMessage()} The data itself is safe; some views, triggers, routines or events may need to be recreated by hand.",
                previous: $e,
            );
        } finally {
            $this->purgeConnectionFor($live);
            $this->purgeConnectionFor($replacement);
        }
    }

    private function renameAllTables(string $live, string $replacement, string $archive): void
    {
        $pairs = [];

        foreach ($this->tableNames($live) as $table) {
            $pairs[] = $this->quoteIdentifier($live).'.'.$this->quoteIdentifier($table)
                .' to '.$this->quoteIdentifier($archive).'.'.$this->quoteIdentifier($table);
        }

        foreach ($this->tableNames($replacement) as $table) {
            $pairs[] = $this->quoteIdentifier($replacement).'.'.$this->quoteIdentifier($table)
                .' to '.$this->quoteIdentifier($live).'.'.$this->quoteIdentifier($table);
        }

        if ($pairs === []) {
            return;
        }

        $this->root()->statement('set session lock_wait_timeout = 30');
        $this->root()->statement('rename table '.implode(', ', $pairs));
    }

    /** @return list<string> */
    private function tableNames(string $database): array
    {
        return array_map(
            fn ($row) => $row->name,
            $this->root()->select(
                "select table_name as name from information_schema.tables where table_schema = ? and table_type = 'BASE TABLE'",
                [$database]
            )
        );
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
