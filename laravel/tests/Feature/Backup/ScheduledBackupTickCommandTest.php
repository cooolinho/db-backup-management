<?php

use App\Jobs\CreateBackupJob;
use App\Models\Backup;
use App\Models\User;
use App\Settings\BackupSettings;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

it('dispatches a scheduled backup when the cron expression is due', function () {
    Bus::fake();

    $settings = app(BackupSettings::class);
    $settings->schedule = '* * * * *'; // due every minute
    $settings->save();

    $this->artisan('backup:tick')->assertSuccessful();

    Bus::assertDispatched(CreateBackupJob::class, fn (CreateBackupJob $job) => $job->source === 'scheduled');
});

it('does not dispatch when the schedule is not due', function () {
    Bus::fake();

    $settings = app(BackupSettings::class);
    // A minute that can never match "now" during a test run.
    $settings->schedule = '7 3 29 2 *'; // Feb 29, 03:07 - leap days only
    $settings->save();

    $this->artisan('backup:tick')->assertSuccessful();

    Bus::assertNotDispatched(CreateBackupJob::class);
});

it('does not dispatch when the schedule is disabled', function () {
    Bus::fake();

    $settings = app(BackupSettings::class);
    $settings->schedule = null;
    $settings->save();

    $this->artisan('backup:tick')->assertSuccessful();

    Bus::assertNotDispatched(CreateBackupJob::class);
});

it('rejects an invalid cron expression', function () {
    Bus::fake();

    $settings = app(BackupSettings::class);
    $settings->schedule = 'not a cron expression';
    $settings->save();

    $this->artisan('backup:tick')->assertFailed();

    Bus::assertNotDispatched(CreateBackupJob::class);
});

it('warns once when backups have gone stale, and does not repeat the warning every tick', function () {
    Bus::fake(); // an hourly schedule may also be "due" at the instant this runs - don't let that dispatch a real job
    Http::fake();
    User::factory()->create();
    config(['backup.notify.webhook_url' => 'https://example.test/hook', 'backup.notify.webhook_type' => 'generic']);

    Backup::create([
        'database' => 'app', 'format' => 'sql', 'source' => 'scheduled', 'status' => 'success',
        'finished_at' => now()->subHours(3),
    ]);

    $settings = app(BackupSettings::class);
    $settings->schedule = '0 * * * *'; // hourly -> stale threshold is 2h, and the last backup was 3h ago
    $settings->save();

    $this->artisan('backup:tick')->assertSuccessful();
    $this->artisan('backup:tick')->assertSuccessful();

    Http::assertSentCount(1);
});

it('does not warn when the last successful backup is recent', function () {
    Http::fake();
    User::factory()->create();
    config(['backup.notify.webhook_url' => 'https://example.test/hook', 'backup.notify.webhook_type' => 'generic']);

    Backup::create([
        'database' => 'app', 'format' => 'sql', 'source' => 'scheduled', 'status' => 'success',
        'finished_at' => now()->subMinutes(5),
    ]);

    $settings = app(BackupSettings::class);
    $settings->schedule = '0 * * * *';
    $settings->save();

    $this->artisan('backup:tick')->assertSuccessful();

    Http::assertNothingSent();
});
