<?php

namespace App\Jobs;

use App\Backup\BackupService;
use App\Backup\Exceptions\OperationInProgressException;
use App\Backup\OperationLock;
use App\Support\FailureNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

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

    public function handle(BackupService $service, OperationLock $lock): void
    {
        $lock->run(fn () => $service->run($this->source, $this->triggeredBy));
    }

    /** A lock conflict is expected/transient, not an operational failure - only genuine errors get reported. */
    public function failed(Throwable $exception): void
    {
        if ($exception instanceof OperationInProgressException) {
            return;
        }

        app(FailureNotifier::class)->report(
            'Backup',
            config('database.connections.target.database'),
            $exception->getMessage(),
        );
    }
}
