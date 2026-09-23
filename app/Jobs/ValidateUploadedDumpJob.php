<?php

namespace App\Jobs;

use App\Backup\ArchiveFormatter;
use App\Backup\DumpValidator;
use App\Models\Backup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Checks an uploaded/imported dump (status=validating) with DumpValidator
 * and records success/failure - the file itself is kept either way, so the
 * uploader can inspect or delete it. Deliberately doesn't throw or go
 * through OperationLock/FailureNotifier: an invalid upload isn't an
 * operational failure of the tool, and does not need to run under the
 * same lock as an actual backup/restore/swap.
 */
class ValidateUploadedDumpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $backupId)
    {
        $this->timeout = (int) config('backup.job_timeout');
    }

    public function handle(ArchiveFormatter $formatter, DumpValidator $validator): void
    {
        $backup = Backup::findOrFail($this->backupId);
        $sqlPath = null;

        try {
            $localPath = Storage::disk('backups')->path($backup->path);
            $sqlPath = sys_get_temp_dir().'/validate_'.bin2hex(random_bytes(8)).'.sql';
            $formatter->unpack($localPath, $sqlPath);

            $validator->validate($sqlPath, config('database.connections.target.driver'));

            $backup->update([
                'status' => 'success',
                'sha256' => hash_file('sha256', $localPath),
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            $backup->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);
        } finally {
            if ($sqlPath !== null && file_exists($sqlPath)) {
                @unlink($sqlPath);
            }
        }
    }
}
