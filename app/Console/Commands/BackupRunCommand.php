<?php

namespace App\Console\Commands;

use App\Backup\BackupService;
use App\Jobs\CreateBackupJob;
use Illuminate\Console\Command;
use Throwable;

class BackupRunCommand extends Command
{
    protected $signature = 'backup:run {--wait : Run synchronously and exit non-zero on failure (e.g. to gate a deployment)}';

    protected $description = 'Create a backup of the target database';

    public function handle(BackupService $service): int
    {
        if (! $this->option('wait')) {
            CreateBackupJob::dispatch(source: 'cli');
            $this->components->info('Backup queued.');

            return self::SUCCESS;
        }

        $this->components->task('Creating backup', function () use (&$backup, $service) {
            try {
                $backup = $service->run(source: 'cli');

                return true;
            } catch (Throwable $e) {
                $this->components->error($e->getMessage());

                return false;
            }
        });

        if (! isset($backup)) {
            return self::FAILURE;
        }

        $this->components->info("Backup #{$backup->id} created: {$backup->path} ({$this->humanSize($backup->size_bytes)})");

        return self::SUCCESS;
    }

    private function humanSize(?int $bytes): string
    {
        if ($bytes === null) {
            return 'unknown size';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 1).' '.$units[$i];
    }
}
