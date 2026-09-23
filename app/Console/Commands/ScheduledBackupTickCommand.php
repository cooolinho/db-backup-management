<?php

namespace App\Console\Commands;

use App\Jobs\CreateBackupJob;
use App\Settings\BackupSettings;
use Cron\CronExpression;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Runs every minute (see routes/console.php) and checks the schedule from
 * BackupSettings - not a fixed cron entry - so changing the interval in the
 * UI takes effect immediately, without restarting the scheduler process.
 */
class ScheduledBackupTickCommand extends Command
{
    protected $signature = 'backup:tick';

    protected $description = 'Dispatch a scheduled backup if the configured interval is due';

    public function handle(BackupSettings $settings): int
    {
        if (blank($settings->schedule)) {
            return self::SUCCESS;
        }

        if (! CronExpression::isValidExpression($settings->schedule)) {
            $this->components->error("Invalid backup schedule cron expression: [{$settings->schedule}]");

            return self::FAILURE;
        }

        $now = now();

        if (! (new CronExpression($settings->schedule))->isDue($now->toDateTimeString())) {
            return self::SUCCESS;
        }

        // isDue() is true for the whole minute; a lock (rather than
        // withoutOverlapping() on this command) guards against firing
        // twice if schedule:work's own tick and a manual run overlap.
        $guard = Cache::lock('backup-tick-'.$now->format('Y-m-d-H-i'), 120);

        if (! $guard->get()) {
            return self::SUCCESS;
        }

        CreateBackupJob::dispatch(source: 'scheduled');

        return self::SUCCESS;
    }
}
