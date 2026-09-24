<?php

namespace App\Console\Commands;

use App\Backup\RestoreService;
use App\Jobs\RestoreBackupJob;
use App\Models\Backup;
use App\Support\FailureNotifier;
use Illuminate\Console\Command;
use Throwable;

/**
 * For automated rollbacks from a deploy script:
 *   php artisan backup:restore 42 --wait --force
 * exits non-zero on failure, so a deploy pipeline can gate on it.
 */
class BackupRestoreCommand extends Command
{
    protected $signature = 'backup:restore
        {backup : The backup ID to restore}
        {--wait : Run synchronously and exit non-zero on failure}
        {--force : Skip the "type the database name to confirm" prompt}';

    protected $description = 'Restore a backup, replacing the live database (its previous content is kept as an archive)';

    public function handle(RestoreService $service, FailureNotifier $notifier): int
    {
        $backup = Backup::find($this->argument('backup'));

        if (! $backup) {
            $this->components->error('Backup not found.');

            return self::FAILURE;
        }

        if (! $backup->isSuccessful() || ! $backup->on_local) {
            $this->components->error('Only a successful, locally-stored backup can be restored.');

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $confirmed = $this->ask("This replaces the database [{$backup->database}]. Type its name to confirm");

            if ($confirmed !== $backup->database) {
                $this->components->error('Confirmation did not match the database name; aborting.');

                return self::FAILURE;
            }
        }

        $restore = $service->prepareRestoreFromBackup($backup, triggeredBy: null);

        if (! $this->option('wait')) {
            RestoreBackupJob::dispatch($restore->id);
            $this->components->info("Restore #{$restore->id} queued.");

            return self::SUCCESS;
        }

        $succeeded = true;

        $this->components->task('Restoring', function () use ($restore, $service, $notifier, &$succeeded) {
            try {
                $service->restoreFromDump($restore);
            } catch (Throwable $e) {
                $this->components->error($e->getMessage());
                $notifier->report('Wiederherstellung', $restore->database, $e->getMessage());
                $succeeded = false;
            }
        });

        if (! $succeeded) {
            return self::FAILURE;
        }

        $restore->refresh();
        $this->components->info("Restored. The previous state is archived as [{$restore->archive_name}].");

        return self::SUCCESS;
    }
}
