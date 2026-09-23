<?php

namespace App\Jobs;

use App\Backup\ArchiveNamer;
use App\Backup\DriverFactory;
use App\Backup\Exceptions\OperationInProgressException;
use App\Backup\OperationLock;
use App\Support\Audit;
use App\Support\FailureNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use Throwable;

/**
 * Deletes an archive database. $archiveName is re-validated against
 * ArchiveNamer::isArchiveOf() here (not just in the UI) so this can never
 * be made to drop the live database or an unrelated one, regardless of
 * where the job was dispatched from.
 */
class DropArchiveJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $database,
        public readonly string $archiveName,
        public readonly ?int $triggeredBy = null,
    ) {
        $this->timeout = (int) config('backup.job_timeout');
    }

    public function handle(DriverFactory $drivers, ArchiveNamer $namer, OperationLock $lock): void
    {
        $lock->run(function () use ($drivers, $namer) {
            if (! $namer->isArchiveOf($this->database, $this->archiveName)) {
                throw new RuntimeException("[{$this->archiveName}] is not a recognized archive of [{$this->database}].");
            }

            $drivers->make()->dropDatabase($this->archiveName);

            Audit::record(
                'archive.dropped',
                "Archiv [{$this->archiveName}] von [{$this->database}] gelöscht",
                $this->archiveName,
                userId: $this->triggeredBy,
            );
        });
    }

    public function failed(Throwable $exception): void
    {
        if ($exception instanceof OperationInProgressException) {
            return;
        }

        app(FailureNotifier::class)->report('Löschen des Archivs', $this->database, $exception->getMessage());
    }
}
