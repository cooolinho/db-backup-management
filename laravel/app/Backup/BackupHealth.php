<?php

namespace App\Backup;

use App\Models\Backup;
use App\Settings\BackupSettings;
use Carbon\CarbonInterval;
use Cron\CronExpression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Whether backups are actually happening on schedule - the watchdog
 * ScheduledBackupTickCommand checks on every tick, to catch a hung queue
 * worker (which wouldn't report a failure itself, since nothing throws -
 * it just silently stops picking up jobs).
 */
class BackupHealth
{
    private const FIRST_OBSERVED_CACHE_KEY = 'backup-health-first-observed-at';

    public function __construct(private readonly BackupSettings $settings) {}

    public function lastSuccessfulBackup(): ?Backup
    {
        return Backup::query()
            ->where('status', 'success')
            ->whereNotNull('finished_at')
            ->orderByDesc('finished_at')
            ->first();
    }

    /** The typical gap between two consecutive scheduled runs, or null when the schedule is disabled/invalid. */
    public function expectedInterval(): ?CarbonInterval
    {
        $cron = $this->settings->schedule;

        if (blank($cron) || ! CronExpression::isValidExpression($cron)) {
            return null;
        }

        $expression = new CronExpression($cron);
        $first = Carbon::instance($expression->getNextRunDate(Carbon::now()));
        $second = Carbon::instance($expression->getNextRunDate($first));

        return $first->diffAsCarbonInterval($second);
    }

    public function nextRunAt(): ?Carbon
    {
        $cron = $this->settings->schedule;

        if (blank($cron) || ! CronExpression::isValidExpression($cron)) {
            return null;
        }

        return Carbon::instance((new CronExpression($cron))->getNextRunDate(Carbon::now()));
    }

    /**
     * More than twice the expected interval has passed since the last
     * successful backup (or, if there has never been one, since the first
     * time this was checked - a freshly deployed container isn't "stale"
     * just because it hasn't had a chance to run yet).
     */
    public function isStale(): bool
    {
        $interval = $this->expectedInterval();

        if ($interval === null) {
            return false;
        }

        $baseline = $this->lastSuccessfulBackup()?->finished_at ?? $this->firstObservedAt();
        $threshold = $baseline->clone()->add($interval)->add($interval);

        return Carbon::now()->greaterThan($threshold);
    }

    private function firstObservedAt(): Carbon
    {
        $stored = Cache::get(self::FIRST_OBSERVED_CACHE_KEY);

        if ($stored !== null) {
            return Carbon::parse($stored);
        }

        $now = Carbon::now();
        Cache::forever(self::FIRST_OBSERVED_CACHE_KEY, $now->toIso8601String());

        return $now;
    }
}
