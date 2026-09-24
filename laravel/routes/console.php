<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The actual interval comes from BackupSettings (editable in the UI), not
// from this fixed entry - see ScheduledBackupTickCommand.
Schedule::command('backup:tick')->everyMinute()->withoutOverlapping();
