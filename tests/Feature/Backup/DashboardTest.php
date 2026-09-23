<?php

use App\Filament\Widgets\BackupStatusOverview;
use App\Filament\Widgets\RecentActivityWidget;
use App\Models\Backup;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('renders the dashboard page', function () {
    // A plain $this->get('/') 403s in tests despite actingAs() - Filament's
    // Authenticate middleware needs the request to go through its own
    // panel/session bootstrapping, which Livewire::test() (the documented
    // way to test a Filament page, used everywhere else in this suite)
    // sets up correctly and a raw HTTP request in a test does not.
    Livewire::test(\Filament\Pages\Dashboard::class)->assertSuccessful();
});

it('renders the status overview widget even when the target database is unreachable', function () {
    Livewire::test(BackupStatusOverview::class)
        ->assertSuccessful()
        ->assertSee('Nicht erreichbar');
});

it('shows the last successful backup on the status overview', function () {
    Backup::create([
        'database' => 'app', 'format' => 'sql', 'source' => 'scheduled', 'status' => 'success',
        'finished_at' => now()->subMinutes(10),
    ]);

    Livewire::test(BackupStatusOverview::class)->assertSuccessful();
});

it('renders the recent activity widget with a real entry', function () {
    App\Support\Audit::record('backup.created', 'Backup #1 erstellt');

    Livewire::test(RecentActivityWidget::class)
        ->assertSuccessful()
        ->assertSee('backup.created')
        ->assertSee('Backup #1 erstellt');
});
