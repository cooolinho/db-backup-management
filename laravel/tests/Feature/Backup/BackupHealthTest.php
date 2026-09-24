<?php

use App\Backup\BackupHealth;
use App\Models\Backup;
use App\Settings\BackupSettings;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-09-23 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

function healthWithSchedule(?string $cron): BackupHealth
{
    $settings = app(BackupSettings::class);
    $settings->schedule = $cron;
    $settings->save();

    return app(BackupHealth::class);
}

it('is never stale when the schedule is disabled', function () {
    expect(healthWithSchedule(null)->isStale())->toBeFalse();
});

it('computes the expected interval from the cron expression', function () {
    $health = healthWithSchedule('0 * * * *'); // hourly

    expect($health->expectedInterval()->totalHours)->toBe(1.0);
});

it('is not stale when a recent successful backup exists', function () {
    Backup::create([
        'database' => 'app', 'format' => 'sql', 'source' => 'scheduled', 'status' => 'success',
        'finished_at' => now()->subMinutes(30),
    ]);

    $health = healthWithSchedule('0 * * * *'); // hourly, threshold = 2h

    expect($health->isStale())->toBeFalse();
});

it('is stale when the last successful backup is older than twice the interval', function () {
    Backup::create([
        'database' => 'app', 'format' => 'sql', 'source' => 'scheduled', 'status' => 'success',
        'finished_at' => now()->subHours(3),
    ]);

    $health = healthWithSchedule('0 * * * *'); // hourly, threshold = 2h

    expect($health->isStale())->toBeTrue();
});

it('ignores a failed backup and looks at the last successful one', function () {
    Backup::create([
        'database' => 'app', 'format' => 'sql', 'source' => 'scheduled', 'status' => 'success',
        'finished_at' => now()->subHours(3),
    ]);
    Backup::create([
        'database' => 'app', 'format' => 'sql', 'source' => 'scheduled', 'status' => 'failed',
        'finished_at' => now()->subMinutes(1),
    ]);

    $health = healthWithSchedule('0 * * * *');

    expect($health->isStale())->toBeTrue();
});

it('is not stale immediately when no backup has ever run yet (first observation is the baseline)', function () {
    $health = healthWithSchedule('0 * * * *');

    expect($health->isStale())->toBeFalse();
});

it('becomes stale relative to the first observation once enough time has passed with no backup', function () {
    $health = healthWithSchedule('0 * * * *');
    expect($health->isStale())->toBeFalse(); // establishes the baseline "now"

    Carbon::setTestNow(now()->addHours(3));

    expect($health->isStale())->toBeTrue();
});
