<?php

namespace App\Console\Commands;

use App\Backup\BackupHealth;
use App\Jobs\CreateBackupJob;
use App\Settings\BackupSettings;
use App\Support\FailureNotifier;
use Cron\CronExpression;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Runs every minute (see routes/console.php) and checks the schedule from
 * BackupSettings - not a fixed cron entry - so changing the interval in the
 * UI takes effect immediately, without restarting the scheduler process.
 * Also the watchdog: a hung queue worker wouldn't report a failure itself
 * (nothing throws - it just silently stops picking up jobs), so this is
 * what actually notices backups have stopped happening.
 */
class ScheduledBackupTickCommand extends Command
{
    protected $signature = 'backup:tick';

    protected $description = 'Dispatch a scheduled backup if due, and warn if backups have stopped happening';

    public function handle(BackupSettings $settings, BackupHealth $health, FailureNotifier $notifier): int
    {
        $this->checkHealth($health, $notifier);

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

    private function checkHealth(BackupHealth $health, FailureNotifier $notifier): void
    {
        if (! $health->isStale()) {
            return;
        }

        // One notification per "stale episode": keyed by the last
        // successful backup's timestamp (or "never"), so this fires again
        // only once a *new* successful backup happens and then goes stale
        // again - not every minute for as long as it stays stale.
        $last = $health->lastSuccessfulBackup();
        $episodeKey = 'backup-health-notified-'.($last?->finished_at?->toIso8601String() ?? 'never');

        if (Cache::has($episodeKey)) {
            return;
        }

        Cache::put($episodeKey, true, now()->addWeek());

        $since = $last?->finished_at?->diffForHumans() ?? 'dem Start des Containers';

        $notifier->report(
            'Backup-Überwachung',
            config('database.connections.target.database'),
            "Seit {$since} gab es kein erfolgreiches Backup mehr. Der geplante Lauf könnte ausgefallen oder der Queue-Worker hängengeblieben sein.",
        );
    }
}
