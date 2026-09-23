<?php

namespace App\Backup;

use App\Models\Backup;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Keeps the last N successful backups per storage location (local/S3),
 * deleting older ones. Local and S3 copies of the SAME backup row are
 * tracked and pruned independently, since one can be deleted while the
 * other is kept. Manual uploads (source=upload) are never auto-deleted.
 */
class RetentionService
{
    public function prune(string $database, int $keepLocal, int $keepS3): void
    {
        $this->pruneDisk($database, 'backups', 'on_local', $keepLocal);
        $this->pruneDisk($database, 's3', 'on_s3', $keepS3);
    }

    private function pruneDisk(string $database, string $disk, string $column, int $keep): void
    {
        if ($keep < 0) {
            return; // negative = keep forever
        }

        $toDelete = Backup::query()
            ->where('database', $database)
            ->where('status', 'success')
            ->where('source', '!=', 'upload')
            ->where($column, true)
            ->orderByDesc('finished_at')
            ->skip($keep)
            ->take(PHP_INT_MAX)
            ->get();

        foreach ($toDelete as $backup) {
            try {
                if ($backup->path && Storage::disk($disk)->exists($backup->path)) {
                    Storage::disk($disk)->delete($backup->path);
                }
            } catch (\Throwable $e) {
                Log::warning("Retention: could not delete [{$backup->path}] from disk [{$disk}]: {$e->getMessage()}");

                continue;
            }

            $backup->{$column} = false;
            $backup->save();

            // Neither copy is kept anywhere anymore: the row has served
            // its purpose (audit/history is covered by the audit log).
            if (! $backup->on_local && ! $backup->on_s3) {
                $backup->delete();
            }
        }
    }
}
