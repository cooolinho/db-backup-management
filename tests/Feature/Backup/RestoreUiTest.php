<?php

use App\Filament\Pages\ArchiveDatabases;
use App\Filament\Resources\Backups\Pages\ListBackups;
use App\Filament\Resources\Restores\Pages\ListRestores;
use App\Filament\Resources\Restores\Pages\ViewRestore;
use App\Models\Backup;
use App\Models\Restore;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('renders the restores list page', function () {
    Livewire::test(ListRestores::class)->assertSuccessful();
});

it('renders a restore row with formatted status and a view action', function () {
    $restore = Restore::create([
        'kind' => 'restore', 'database' => 'app', 'status' => 'success',
        'source_archive' => '/backups/app.sql.gz', 'archive_name' => 'app_20260923_1400_v1',
        'started_at' => now()->subSeconds(5), 'finished_at' => now(),
    ]);

    Livewire::test(ListRestores::class)
        ->assertSuccessful()
        ->assertSee('app')
        ->assertSee('Erfolgreich')
        ->assertTableActionExists('view', record: $restore);
});

it('renders the restore view page with its log', function () {
    $restore = Restore::create([
        'kind' => 'restore', 'database' => 'app', 'status' => 'failed',
        'source_archive' => '/backups/app.sql.gz', 'error_message' => 'Something went wrong',
        'log' => "[10:00:00] fetching: reading\n[10:00:01] failed: Something went wrong",
        'started_at' => now(), 'finished_at' => now(),
    ]);

    Livewire::test(ViewRestore::class, ['record' => $restore->getKey()])
        ->assertSuccessful()
        ->assertSee('Something went wrong')
        ->assertSee('fetching');
});

it('renders the archive databases page', function () {
    Livewire::test(ArchiveDatabases::class)->assertSuccessful();
});

it('shows the restore action only for a successful, locally stored backup', function () {
    $restorable = Backup::create([
        'database' => 'app', 'format' => 'sql.gz', 'path' => 'a.sql.gz',
        'source' => 'manual', 'status' => 'success', 'on_local' => true,
    ]);
    $onS3Only = Backup::create([
        'database' => 'app', 'format' => 'sql.gz', 'path' => 'b.sql.gz',
        'source' => 'manual', 'status' => 'success', 'on_local' => false, 'on_s3' => true,
    ]);
    $failed = Backup::create([
        'database' => 'app', 'format' => 'sql.gz', 'path' => null,
        'source' => 'manual', 'status' => 'failed', 'on_local' => false,
    ]);

    Livewire::test(ListBackups::class)
        ->assertTableActionVisible('restore', record: $restorable)
        ->assertTableActionHidden('restore', record: $onS3Only)
        ->assertTableActionHidden('restore', record: $failed);
});

it('requires the exact database name to confirm a restore', function () {
    $backup = Backup::create([
        'database' => 'app', 'format' => 'sql.gz', 'path' => 'a.sql.gz',
        'source' => 'manual', 'status' => 'success', 'on_local' => true,
    ]);

    Livewire::test(ListBackups::class)
        ->callTableAction('restore', $backup, data: ['confirm' => 'not-the-right-name'])
        ->assertHasTableActionErrors(['confirm']);

    expect(Restore::count())->toBe(0);
});
