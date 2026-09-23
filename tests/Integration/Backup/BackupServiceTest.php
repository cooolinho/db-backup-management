<?php

use App\Backup\BackupService;
use App\Models\Backup;
use App\Settings\BackupSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

function useMariaDbTarget(): void
{
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
        'backup.driver' => 'mariadb',
    ]);
    DB::purge('target');
    DB::purge('target_root');
}

it('runs a full backup end to end: dump, pack, checksum, store, retention', function () {
    useMariaDbTarget();
    Storage::fake('backups');

    DB::connection('target')->statement('drop table if exists customers');
    DB::connection('target')->statement('create table customers (id int primary key, name varchar(100))');
    DB::connection('target')->table('customers')->insert(['id' => 1, 'name' => 'Ada']);

    $settings = app(BackupSettings::class);
    $settings->format = 'sql.gz';
    $settings->keepLocal = 1;
    $settings->save();

    $backup = app(BackupService::class)->run(source: 'cli');

    expect($backup->status)->toBe('success')
        ->and($backup->on_local)->toBeTrue()
        ->and($backup->path)->toEndWith('.sql.gz')
        ->and($backup->size_bytes)->toBeGreaterThan(0)
        ->and($backup->sha256)->toHaveLength(64);

    Storage::disk('backups')->assertExists($backup->path);

    $bytes = Storage::disk('backups')->get($backup->path);
    expect(hash('sha256', $bytes))->toBe($backup->sha256);

    DB::connection('target')->statement('drop table if exists customers');
});

it('marks the backup row as failed instead of throwing away the record when the dump fails', function () {
    useMariaDbTarget();
    Storage::fake('backups');

    // Point at a database that doesn't exist so the dump fails.
    config(['database.connections.target.database' => 'does_not_exist_xyz']);
    DB::purge('target');

    expect(fn () => app(BackupService::class)->run(source: 'cli'))->toThrow(RuntimeException::class);

    $backup = Backup::latest('id')->first();
    expect($backup->status)->toBe('failed')
        ->and($backup->error_message)->not->toBeEmpty();
});
