<?php

namespace App\Backup;

use App\Models\Backup;
use App\Settings\BackupSettings;
use App\Support\Audit;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Orchestrates one backup run: dump -> pack -> checksum -> upload -> retention.
 * The heavy lifting (talking to the server, formatting the archive) lives in
 * DriverFactory/ArchiveFormatter; this class just sequences them and keeps
 * the Backup row honest about what happened.
 */
class BackupService
{
    public function __construct(
        private readonly DriverFactory $drivers,
        private readonly ArchiveFormatter $formatter,
        private readonly RetentionService $retention,
    ) {}

    /** @param 'scheduled'|'manual'|'cli' $source */
    public function run(string $source, ?int $triggeredBy = null): Backup
    {
        $database = config('database.connections.target.database');
        $settings = app(BackupSettings::class);

        $backup = Backup::create([
            'database' => $database,
            'format' => $settings->format,
            'source' => $source,
            'status' => 'running',
            'triggered_by' => $triggeredBy,
            'started_at' => now(),
        ]);

        Audit::record('backup.requested', "Backup von [{$database}] angefordert ({$source})", $backup, userId: $triggeredBy);

        $rawPath = sys_get_temp_dir().'/dump_'.bin2hex(random_bytes(8)).'.sql';

        try {
            $this->drivers->make()->dump($database, $rawPath);

            $baseName = $database.'_'.now()->format('Ymd_His');
            $destinationWithoutExtension = Storage::disk('backups')->path($baseName);

            $finalPath = $this->formatter->pack(
                $rawPath,
                $destinationWithoutExtension,
                $settings->format,
                "{$database}.sql",
            );
            $relativePath = basename($finalPath);

            $backup->update([
                'path' => $relativePath,
                'size_bytes' => filesize($finalPath),
                'sha256' => hash_file('sha256', $finalPath),
                'on_local' => true,
                'status' => 'success',
                'finished_at' => now(),
            ]);

            if ($settings->s3Enabled) {
                $this->uploadToS3($backup, $finalPath, $relativePath);
            }

            $this->retention->prune($database, $settings->keepLocal, $settings->keepS3);

            Audit::record('backup.created', "Backup #{$backup->id} von [{$database}] erstellt", $backup, [
                'size_bytes' => $backup->size_bytes,
                'format' => $backup->format,
            ], userId: $triggeredBy);

            return $backup;
        } catch (Throwable $e) {
            Log::error("Backup of [{$database}] failed: {$e->getMessage()}", ['exception' => $e]);

            $backup->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            Audit::record('backup.failed', "Backup von [{$database}] fehlgeschlagen: {$e->getMessage()}", $backup, userId: $triggeredBy);

            throw $e;
        } finally {
            if (file_exists($rawPath)) {
                @unlink($rawPath);
            }
        }
    }

    private function uploadToS3(Backup $backup, string $localPath, string $relativePath): void
    {
        try {
            $stream = fopen($localPath, 'r');
            Storage::disk('s3')->put($relativePath, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
            $backup->update(['on_s3' => true]);
        } catch (Throwable $e) {
            // The local copy is safe; S3 is a secondary copy, so a failed
            // upload doesn't fail the whole backup - just log it.
            Log::warning("Backup #{$backup->id}: S3 upload failed: {$e->getMessage()}");
        }
    }
}
