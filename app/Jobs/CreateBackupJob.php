<?php

namespace App\Jobs;

use App\Backup\BackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Only one of CreateBackupJob/RestoreBackupJob/SwapArchiveJob may run at a
 * time (the "db-operation" lock, shared across all of them) - the single
 * queue worker already serializes jobs dispatched through it, but this
 * also protects against `backup:run` (or another CLI command) running
 * synchronously in a separate process at the same time.
 */
class CreateBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $source,
        public readonly ?int $triggeredBy = null,
    ) {
        $this->timeout = (int) config('backup.job_timeout');
    }

    public function handle(BackupService $service): void
    {
        $lock = Cache::lock('db-operation', (int) config('backup.job_timeout'));

        if (! $lock->get()) {
            throw new RuntimeException('Another backup, restore or swap is already running.');
        }

        try {
            $service->run($this->source, $this->triggeredBy);
        } finally {
            $lock->release();
        }
    }
}
