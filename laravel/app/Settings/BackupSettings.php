<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * User-editable backup settings (UI: app/Filament/Pages/BackupSettingsPage.php).
 * Seeded from config('backup.defaults'/'s3') on first migrate; the .env
 * values have no further effect after that unless the settings are reset.
 */
class BackupSettings extends Settings
{
    /** Cron expression; null disables the scheduler. */
    public ?string $schedule;

    /** sql | sql.gz | zip | tar.gz */
    public string $format;

    public int $keepLocal;

    public int $keepS3;

    public bool $s3Enabled;

    public static function group(): string
    {
        return 'backup';
    }
}
