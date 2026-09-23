<?php

namespace App\Backup;

use App\Backup\Exceptions\OperationInProgressException;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Ensures at most one of CreateBackupJob/RestoreBackupJob/SwapArchiveJob/
 * DropArchiveJob runs at a time. The single queue worker already
 * serializes jobs dispatched through it, but this also protects against a
 * synchronous CLI command (`backup:run --wait`, `backup:restore --wait`)
 * running in a separate process at the same time.
 */
class OperationLock
{
    private const KEY = 'db-operation';

    /**
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    public function run(Closure $callback): mixed
    {
        $lock = Cache::lock(self::KEY, (int) config('backup.job_timeout'));

        if (! $lock->get()) {
            throw new OperationInProgressException(
                'Another backup, restore or swap is already running.'
            );
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /** Whether a backup/restore/swap is currently running - used to disable UI actions. */
    public function isHeld(): bool
    {
        // Cache::lock()->get() (no callback) attempts to acquire the lock
        // immediately; if that succeeds, nothing was holding it - so we
        // must release what we just acquired before reporting "free".
        $lock = Cache::lock(self::KEY, 5);

        if ($lock->get()) {
            $lock->release();

            return false;
        }

        return true;
    }
}
