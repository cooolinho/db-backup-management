<?php

namespace App\Jobs;

use App\Backup\Exceptions\OperationInProgressException;
use App\Backup\OperationLock;
use App\Backup\RestoreService;
use App\Models\Restore;
use App\Support\FailureNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/** Progresses an already-created (status=pending, kind=swap_back) Restore row through RestoreService::swapFromArchive(). */
class SwapArchiveJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $restoreId)
    {
        $this->timeout = (int) config('backup.job_timeout');
    }

    public function handle(RestoreService $service, OperationLock $lock): void
    {
        $restore = Restore::findOrFail($this->restoreId);

        $lock->run(fn () => $service->swapFromArchive($restore));
    }

    public function failed(Throwable $exception): void
    {
        if ($exception instanceof OperationInProgressException) {
            return;
        }

        $database = Restore::find($this->restoreId)?->database ?? config('database.connections.target.database');

        app(FailureNotifier::class)->report('Zurücktauschen', $database, $exception->getMessage());
    }
}
