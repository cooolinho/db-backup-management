<?php

use App\Jobs\CreateBackupJob;
use App\Settings\BackupSettings;
use Illuminate\Support\Facades\Bus;

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
