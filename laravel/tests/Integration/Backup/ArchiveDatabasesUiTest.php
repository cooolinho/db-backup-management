<?php

use App\Backup\ArchiveNamer;
use App\Backup\Drivers\MariaDbDriver;
use App\Filament\Pages\ArchiveDatabases;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('lists a real archive database and can swap it back in', function () {
    config([
        'database.connections.target' => [
            'driver' => 'mariadb', 'host' => 'mariadb', 'port' => 3306, 'database' => 'app',
            'username' => 'app', 'password' => 'secret', 'unix_socket' => '',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'strict' => true,
        ],
        'database.connections.target_root' => [
            'driver' => 'mariadb', 'host' => 'mariadb', 'port' => 3306, 'database' => 'app',
            'username' => 'root', 'password' => 'root', 'unix_socket' => '',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'strict' => true,
        ],
    ]);
    DB::purge('target');
    DB::purge('target_root');

    $this->actingAs(User::factory()->create());

    $driver = new MariaDbDriver();
    $namer = new ArchiveNamer();
    $archiveName = $namer->next('app', $driver->listDatabases('app'));

    $driver->createDatabase($archiveName, like: 'app');

    try {
        Livewire::test(ArchiveDatabases::class)
            ->assertSuccessful()
            ->assertSee($archiveName)
            ->assertTableActionExists('swapBack', record: $archiveName)
            ->callTableAction('swapBack', $archiveName, data: [
                'confirm' => $archiveName,
            ])
            ->assertHasNoTableActionErrors();

        // QUEUE_CONNECTION=sync in tests (see phpunit.xml), so the job
        // already ran synchronously by the time callTableAction() returns.
        $restore = App\Models\Restore::where('kind', 'swap_back')->where('source_archive', $archiveName)->sole();
        expect($restore->status)->toBe('success')
            ->and($restore->archive_name)->not->toBeNull();

        if ($driver->databaseExists($restore->archive_name)) {
            $driver->dropDatabase($restore->archive_name);
        }
    } finally {
        if ($driver->databaseExists($archiveName)) {
            $driver->dropDatabase($archiveName);
        }
    }
});
