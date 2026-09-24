<?php

namespace App\Backup;

use App\Backup\Drivers\DatabaseDriver;
use App\Models\Backup;
use App\Models\Restore;
use App\Support\Audit;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Orchestrates the two ways a database can change: restoring a backup
 * file (restoreFromDump) and swapping a previously archived database back
 * in (swapFromArchive). Both end in DatabaseDriver::swap(), which is what
 * actually keeps the previous content as a new archive instead of losing it.
 */
class RestoreService
{
    public function __construct(
        private readonly DriverFactory $drivers,
        private readonly ArchiveFormatter $formatter,
        private readonly DumpValidator $validator,
        private readonly ArchiveNamer $namer,
    ) {}

    /** Creates the pending row; the actual work happens later in RestoreBackupJob via restoreFromDump(). */
    public function prepareRestoreFromBackup(Backup $backup, ?int $triggeredBy = null): Restore
    {
        if (! $backup->on_local) {
            throw new RuntimeException('Only backups stored locally can be restored.');
        }

        $restore = Restore::create([
            'backup_id' => $backup->id,
            'kind' => 'restore',
            'database' => $backup->database,
            'source_archive' => Storage::disk('backups')->path($backup->path),
            'status' => 'pending',
            'triggered_by' => $triggeredBy,
        ]);

        Audit::record('restore.requested', "Wiederherstellung von [{$backup->database}] aus Backup #{$backup->id} angefordert", $restore, userId: $triggeredBy);

        return $restore;
    }

    /** Creates the pending row; the actual work happens later in SwapArchiveJob via swapFromArchive(). */
    public function prepareSwapBack(string $database, string $archiveName, ?int $triggeredBy = null): Restore
    {
        $restore = Restore::create([
            'kind' => 'swap_back',
            'database' => $database,
            'source_archive' => $archiveName,
            'status' => 'pending',
            'triggered_by' => $triggeredBy,
        ]);

        Audit::record('restore.requested', "Zurücktauschen von [{$database}] zu Archiv [{$archiveName}] angefordert", $restore, userId: $triggeredBy);

        return $restore;
    }

    public function restoreFromDump(Restore $restore): void
    {
        $driver = $this->drivers->make();
        $database = $restore->database;
        $tmpDatabase = $this->namer->tmpName($database);
        $archivePath = $restore->source_archive;

        $restore->update(['tmp_database' => $tmpDatabase, 'status' => 'running', 'started_at' => now()]);

        $sqlPath = null;

        try {
            $restore->appendLog('fetching', "Reading {$archivePath}");

            if (! is_string($archivePath) || ! file_exists($archivePath)) {
                throw new RuntimeException("Backup file not found: {$archivePath}");
            }

            $restore->appendLog('unpacking', 'Extracting to plain SQL');
            $sqlPath = sys_get_temp_dir().'/restore_'.bin2hex(random_bytes(8)).'.sql';
            $this->formatter->unpack($archivePath, $sqlPath);

            $restore->appendLog('validating', 'Checking the dump for database-level statements');
            $this->validator->validate($sqlPath, config('database.connections.target.driver'));

            $restore->appendLog('preparing', "Creating temporary database [{$tmpDatabase}]");
            if ($driver->databaseExists($tmpDatabase)) {
                $driver->dropDatabase($tmpDatabase);
            }
            $driver->createDatabase($tmpDatabase, like: $database);

            $restore->appendLog('importing', 'Importing the dump');
            $driver->import($tmpDatabase, $sqlPath);

            $restore->appendLog('checking', 'Sanity-checking the imported data');
            if ($driver->tableCount($tmpDatabase) === 0) {
                throw new RuntimeException('The imported database has no tables - refusing to swap it in.');
            }

            $this->swap($restore, $driver, $database, $tmpDatabase);
        } catch (Throwable $e) {
            // Only drop it if it's still there under its own name - a
            // failure inside swap() past the point of no return means
            // $tmpDatabase's content is now live, not this name anymore.
            if ($driver->databaseExists($tmpDatabase)) {
                try {
                    $driver->dropDatabase($tmpDatabase);
                } catch (Throwable $cleanupError) {
                    Log::warning("Restore #{$restore->id}: could not drop leftover [{$tmpDatabase}]: {$cleanupError->getMessage()}");
                }
            }

            $this->fail($restore, $e);

            throw $e;
        } finally {
            if ($sqlPath !== null && file_exists($sqlPath)) {
                @unlink($sqlPath);
            }
        }
    }

    public function swapFromArchive(Restore $restore): void
    {
        $driver = $this->drivers->make();
        $database = $restore->database;
        $archiveName = $restore->source_archive;

        $restore->update(['status' => 'running', 'started_at' => now()]);

        try {
            $restore->appendLog('checking', "Verifying [{$archiveName}] is a recognized archive of [{$database}]");

            if (! is_string($archiveName) || ! $this->namer->isArchiveOf($database, $archiveName)) {
                throw new RuntimeException("[{$archiveName}] is not a recognized archive of [{$database}].");
            }
            if (! $driver->databaseExists($archiveName)) {
                throw new RuntimeException("Archive database [{$archiveName}] does not exist.");
            }

            $this->swap($restore, $driver, $database, $archiveName);
        } catch (Throwable $e) {
            $this->fail($restore, $e);

            throw $e;
        }
    }

    private function swap(Restore $restore, DatabaseDriver $driver, string $database, string $replacement): void
    {
        $restore->appendLog('swapping', "Replacing [{$database}], archiving its current content");

        $newArchiveName = $this->namer->next($database, $driver->listDatabases($database));
        $restore->update(['archive_name' => $newArchiveName]);

        $driver->swap($database, $replacement, $newArchiveName);

        $restore->update(['status' => 'success', 'finished_at' => now()]);
        $restore->appendLog('done', "Done. The previous content of [{$database}] is archived as [{$newArchiveName}].");

        Audit::record('restore.succeeded', "[{$database}] erfolgreich ersetzt, vorheriger Stand als [{$newArchiveName}] archiviert", $restore, userId: $restore->triggered_by);
    }

    private function fail(Restore $restore, Throwable $e): void
    {
        $restore->update(['status' => 'failed', 'error_message' => $e->getMessage(), 'finished_at' => now()]);
        $restore->appendLog('failed', $e->getMessage());

        Audit::record('restore.failed', "Wiederherstellung von [{$restore->database}] fehlgeschlagen: {$e->getMessage()}", $restore, userId: $restore->triggered_by);
    }
}
