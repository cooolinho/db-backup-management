<?php

namespace App\Backup;

use App\Jobs\ValidateUploadedDumpJob;
use App\Models\Backup;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Turns an uploaded (or `backup:import`-registered) dump file into a
 * Backup row with status=validating, and queues ValidateUploadedDumpJob
 * to actually check it. Kept separate from BackupService: this doesn't
 * dump anything, it registers a file that already exists.
 */
class UploadedDumpRegistrar
{
    public function __construct(private readonly ArchiveFormatter $formatter) {}

    /**
     * @param string $storedPath Path to the dump file on the 'backups' disk (already there - e.g. Livewire's temp upload directory).
     * @param string $originalName The user-supplied filename, used only to detect the format (sql/sql.gz/zip/tar.gz) and build the final name.
     * @param 'upload'|'cli' $source
     * @param bool $queueValidation False when the caller (backup:import) runs validation itself synchronously right afterward instead.
     */
    public function register(
        string $storedPath,
        string $originalName,
        string $source,
        ?int $triggeredBy = null,
        bool $queueValidation = true,
    ): Backup {
        $format = $this->formatter->detectFormat($originalName);

        $finalPath = 'upload_'.now()->format('Ymd_His').'_'.Str::slug(pathinfo($originalName, PATHINFO_FILENAME)).'.'.$format;

        Storage::disk('backups')->move($storedPath, $finalPath);

        $backup = Backup::create([
            'database' => config('database.connections.target.database'),
            'format' => $format,
            'path' => $finalPath,
            'size_bytes' => Storage::disk('backups')->size($finalPath),
            'on_local' => true,
            'source' => $source,
            'status' => 'validating',
            'triggered_by' => $triggeredBy,
            'started_at' => now(),
        ]);

        if ($queueValidation) {
            ValidateUploadedDumpJob::dispatch($backup->id);
        }

        return $backup;
    }
}
