<?php

namespace App\Console\Commands;

use App\Backup\ArchiveFormatter;
use App\Backup\DumpValidator;
use App\Backup\UploadedDumpRegistrar;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * For a dump too large to comfortably upload through the browser: copy it
 * onto the server (e.g. via scp) and register it directly.
 *   php artisan backup:import /path/to/dump.sql.gz --move
 * Validation runs synchronously here (not queued), so the command's exit
 * code reflects whether the dump is actually usable.
 */
class BackupImportCommand extends Command
{
    protected $signature = 'backup:import
        {path : Local path to the dump file (.sql, .sql.gz, .zip or .tar.gz)}
        {--move : Move the file instead of copying it}';

    protected $description = 'Register a local dump file as an upload and validate it';

    public function handle(UploadedDumpRegistrar $registrar, ArchiveFormatter $formatter, DumpValidator $validator): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->components->error("File not found: {$path}");

            return self::FAILURE;
        }

        try {
            $format = $formatter->detectFormat($path);
        } catch (\InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $stagedName = '.incoming/'.basename($path);
        $stream = fopen($path, 'r');
        Storage::disk('backups')->put($stagedName, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        if ($this->option('move')) {
            @unlink($path);
        }

        $backup = $registrar->register($stagedName, basename($path), source: 'cli', queueValidation: false);

        $succeeded = true;

        $this->components->task('Validating', function () use ($backup, $formatter, $validator, &$succeeded) {
            $sqlPath = sys_get_temp_dir().'/validate_'.bin2hex(random_bytes(8)).'.sql';

            try {
                $localPath = Storage::disk('backups')->path($backup->path);
                $formatter->unpack($localPath, $sqlPath);
                $validator->validate($sqlPath, config('database.connections.target.driver'));

                $backup->update([
                    'status' => 'success',
                    'sha256' => hash_file('sha256', $localPath),
                    'finished_at' => now(),
                ]);
            } catch (\Throwable $e) {
                $backup->update(['status' => 'failed', 'error_message' => $e->getMessage(), 'finished_at' => now()]);
                $this->components->error($e->getMessage());
                $succeeded = false;
            } finally {
                if (file_exists($sqlPath)) {
                    @unlink($sqlPath);
                }
            }
        });

        if (! $succeeded) {
            return self::FAILURE;
        }

        $this->components->info("Backup #{$backup->id} registered as [{$backup->path}].");

        return self::SUCCESS;
    }
}
