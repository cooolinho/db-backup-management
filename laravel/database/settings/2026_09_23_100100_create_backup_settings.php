<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('backup.schedule', config('backup.defaults.schedule'));
        $this->migrator->add('backup.format', config('backup.defaults.format'));
        $this->migrator->add('backup.keepLocal', config('backup.defaults.keep_local'));
        $this->migrator->add('backup.keepS3', config('backup.defaults.keep_s3'));
        $this->migrator->add('backup.s3Enabled', config('backup.s3.enabled'));
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('backup.schedule');
        $this->migrator->deleteIfExists('backup.format');
        $this->migrator->deleteIfExists('backup.keepLocal');
        $this->migrator->deleteIfExists('backup.keepS3');
        $this->migrator->deleteIfExists('backup.s3Enabled');
    }
};
