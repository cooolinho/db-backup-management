<?php

use App\Filament\Pages\ManageBackupSettings;
use App\Filament\Resources\Backups\Pages\ListBackups;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('renders the backups list page', function () {
    Livewire::test(ListBackups::class)->assertSuccessful();
});

it('renders a real backup row with formatted status, size and duration', function () {
    $backup = \App\Models\Backup::create([
        'database' => 'app',
        'format' => 'sql.gz',
        'path' => 'app_20260923_140106.sql.gz',
        'size_bytes' => 353,
        'sha256' => str_repeat('a', 64),
        'on_local' => true,
        'on_s3' => false,
        'source' => 'cli',
        'status' => 'success',
        'started_at' => now()->subSeconds(5),
        'finished_at' => now(),
    ]);

    Livewire::test(ListBackups::class)
        ->assertSuccessful()
        ->assertSee('app')
        ->assertSee('Erfolgreich')
        ->assertSee('353')
        ->assertTableActionExists('download', record: $backup)
        ->assertTableActionExists('delete', record: $backup);
});

it('renders the backup settings page with defaults from config', function () {
    Livewire::test(ManageBackupSettings::class)
        ->assertSuccessful()
        ->assertFormSet([
            'format' => config('backup.defaults.format'),
            'keepLocal' => config('backup.defaults.keep_local'),
        ]);
});

it('turns a daily preset with a time into the matching cron expression on save', function () {
    Livewire::test(ManageBackupSettings::class)
        ->fillForm([
            'schedulePreset' => 'daily',
            'dailyTime' => '14:30',
            'format' => 'sql.gz',
            'keepLocal' => 5,
            's3Enabled' => false,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(App\Settings\BackupSettings::class)->schedule)->toBe('30 14 * * *');
});

it('requires a valid cron expression for the custom preset', function () {
    Livewire::test(ManageBackupSettings::class)
        ->fillForm([
            'schedulePreset' => 'custom',
            'schedule' => 'not a cron expression',
            'format' => 'sql.gz',
            'keepLocal' => 5,
            's3Enabled' => false,
        ])
        ->call('save')
        ->assertHasFormErrors(['schedule']);
});
