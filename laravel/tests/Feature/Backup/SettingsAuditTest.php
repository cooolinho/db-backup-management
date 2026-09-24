<?php

use App\Filament\Pages\ManageBackupSettings;
use App\Models\AuditLog;
use App\Models\User;
use Livewire\Livewire;

it('records a settings.updated audit entry with the changed fields only', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(ManageBackupSettings::class)
        ->fillForm([
            'schedulePreset' => 'hourly',
            'format' => 'zip',
            'keepLocal' => 30,
            's3Enabled' => false,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $entry = AuditLog::where('action', 'settings.updated')->sole();

    expect($entry->properties)->toHaveKeys(['schedule', 'format', 'keepLocal'])
        ->and($entry->properties['format'])->toContain('sql.gz')
        ->and($entry->properties['format'])->toContain('zip');
});

it('does not record an audit entry when nothing actually changed', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(ManageBackupSettings::class)
        ->call('save')
        ->assertHasNoFormErrors();

    expect(AuditLog::where('action', 'settings.updated')->exists())->toBeFalse();
});
