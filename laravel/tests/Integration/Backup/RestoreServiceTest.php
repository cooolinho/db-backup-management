<?php

use App\Backup\ArchiveFormatter;
use App\Backup\ArchiveNamer;
use App\Backup\DriverFactory;
use App\Backup\DumpValidator;
use App\Backup\RestoreService;
use App\Models\Backup;
use App\Models\Restore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Self-contained on purpose (not sharing SwapTest.php's dataset/helpers):
// Pest only registers a dataset from files actually loaded in the current
// run, so `--filter=RestoreServiceTest` alone would leave a shared
// dataset undefined.

dataset('restore_engines', [
    'mysql' => [fn () => new App\Backup\Drivers\MySqlDriver(), [
        'driver' => 'mysql', 'host' => 'mysql', 'port' => 3306, 'database' => 'app',
        'username' => 'app', 'password' => 'secret',
        'root_username' => 'root', 'root_password' => 'root',
    ]],
    'mariadb' => [fn () => new App\Backup\Drivers\MariaDbDriver(), [
        'driver' => 'mariadb', 'host' => 'mariadb', 'port' => 3306, 'database' => 'app',
        'username' => 'app', 'password' => 'secret',
        'root_username' => 'root', 'root_password' => 'root',
    ]],
    'pgsql' => [fn () => new App\Backup\Drivers\PostgresDriver(), [
        'driver' => 'pgsql', 'host' => 'postgres', 'port' => 5432, 'database' => 'app',
        'username' => 'app', 'password' => 'secret',
        'root_username' => 'postgres', 'root_password' => 'root',
    ]],
]);

function configureRestoreEngine(array $params): void
{
    $build = fn (string $username, string $password) => match ($params['driver']) {
        'pgsql' => [
            'driver' => 'pgsql', 'host' => $params['host'], 'port' => $params['port'],
            'database' => $params['database'], 'username' => $username, 'password' => $password,
            'charset' => 'utf8', 'search_path' => 'public', 'sslmode' => 'prefer',
        ],
        default => [
            'driver' => $params['driver'], 'host' => $params['host'], 'port' => $params['port'],
            'database' => $params['database'], 'username' => $username, 'password' => $password,
            'unix_socket' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'strict' => true,
        ],
    };

    config([
        'database.connections.target' => $build($params['username'], $params['password']),
        'database.connections.target_root' => $build($params['root_username'], $params['root_password']),
    ]);
    DB::purge('target');
    DB::purge('target_root');
}

function restoreConnectAsRoot(array $params, string $database): \Illuminate\Database\Connection
{
    $config = config('database.connections.target_root');
    $config['database'] = $database;
    config(['database.connections.swap_verify' => $config]);
    DB::purge('swap_verify');

    return DB::connection('swap_verify');
}

function restoreConnectAsApp(array $params, string $database): \Illuminate\Database\Connection
{
    $config = config('database.connections.target');
    $config['database'] = $database;
    config(['database.connections.swap_verify' => $config]);
    DB::purge('swap_verify');

    return DB::connection('swap_verify');
}

function restoreDropIfExists(App\Backup\Drivers\DatabaseDriver $driver, string $database): void
{
    if ($driver->databaseExists($database)) {
        $driver->dropDatabase($database);
    }
}

/**
 * For seeding tables directly on the live database in a test. PostgreSQL
 * access is ownership-based: a table root creates directly isn't owned by
 * (or necessarily even readable/dumpable by) the app user, so pg_dump as
 * the app user then fails with "permission denied". MySQL/MariaDB access
 * is schema-name-based instead - the app user's grant on `app`.* covers
 * whatever tables exist there regardless of who created them - so seeding
 * via root is fine, and is what real migrations effectively look like
 * from this test's point of view either way.
 */
function restoreLiveSeedConnection(array $params): \Illuminate\Database\Connection
{
    return DB::connection($params['driver'] === 'pgsql' ? 'target' : 'target_root');
}

function makeRestoreService(): RestoreService
{
    return new RestoreService(new DriverFactory(), new ArchiveFormatter(), new DumpValidator(), new ArchiveNamer());
}

beforeEach(function () {
    Storage::fake('backups');
});

it('restores a backup end to end: live gets the backup content, the previous content is archived, views/triggers still work, and the app user can read and write', function ($makeDriver, array $params) {
    configureRestoreEngine($params);
    $driver = $makeDriver();
    $service = makeRestoreService();

    $database = $params['database'];
    $root = DB::connection('target_root');
    $seed = restoreLiveSeedConnection($params);

    restoreDropIfExists($driver, $database.'_restore_tmp');

    // --- Seed "live" with a table, a view and (for a meaningful check) a trigger ---
    if ($params['driver'] === 'pgsql') {
        $root->statement('drop table if exists widgets cascade');
        $seed->statement('create table widgets (id integer primary key, name varchar(100))');
        $seed->statement('create or replace view widget_names as select id, name from widgets');
    } else {
        $seed->statement('drop view if exists widget_names');
        $seed->statement('drop table if exists widgets');
        $seed->statement('create table widgets (id int primary key, name varchar(100))');
        $seed->statement('create view widget_names as select id, name from widgets');
    }
    $seed->table('widgets')->insert(['id' => 1, 'name' => 'backup-time-widget']);

    // --- Create a real backup via the driver (same path BackupService uses) ---
    $dumpPath = sys_get_temp_dir().'/restore_test_dump_'.bin2hex(random_bytes(6)).'.sql';
    $driver->dump($database, $dumpPath);
    $archivePath = Storage::disk('backups')->path('restore_test_'.bin2hex(random_bytes(6)).'.sql');
    rename($dumpPath, $archivePath);

    $backup = Backup::create([
        'database' => $database, 'format' => 'sql', 'path' => basename($archivePath),
        'source' => 'manual', 'status' => 'success', 'on_local' => true,
        'started_at' => now(), 'finished_at' => now(),
    ]);

    // --- Change live's data after the backup, so restore has something to actually undo ---
    $root->table('widgets')->where('id', 1)->update(['name' => 'changed-after-backup']);
    $root->table('widgets')->insert(['id' => 2, 'name' => 'added-after-backup']);

    $restore = $service->prepareRestoreFromBackup($backup);

    try {
        $service->restoreFromDump($restore->fresh());
        $restore->refresh();

        expect($restore->status)->toBe('success')
            ->and($restore->archive_name)->not->toBeNull()
            ->and($driver->databaseExists($restore->tmp_database))->toBeFalse(); // no _restore_tmp leftovers

        // live now has the backup-time content
        $liveConn = restoreConnectAsRoot($params, $database);
        expect($liveConn->table('widgets')->pluck('name', 'id')->all())->toBe([1 => 'backup-time-widget'])
            ->and($liveConn->table('widget_names')->pluck('name')->all())->toBe(['backup-time-widget']);

        // the app user (not just root) can read and write the restored live database
        $appConn = restoreConnectAsApp($params, $database);
        expect($appConn->table('widgets')->count())->toBe(1);
        $appConn->table('widgets')->insert(['id' => 3, 'name' => 'written-by-app-after-restore']);
        expect($appConn->table('widgets')->count())->toBe(2);

        // the archive has what live held right before the restore
        $archiveConn = restoreConnectAsRoot($params, $restore->archive_name);
        expect($archiveConn->table('widgets')->pluck('name', 'id')->all())
            ->toBe([1 => 'changed-after-backup', 2 => 'added-after-backup']);

        DB::purge('swap_verify'); // close before dropping: Postgres refuses to drop a database with open connections
        restoreDropIfExists($driver, $restore->archive_name);
    } finally {
        DB::purge('swap_verify');

        if ($params['driver'] === 'pgsql') {
            $root->statement('drop table if exists widgets cascade');
        } else {
            $root->statement('drop view if exists widget_names');
            $root->statement('drop table if exists widgets');
        }
    }
})->with('restore_engines');

it('leaves the live database untouched and marks the restore failed when the dump is invalid', function ($makeDriver, array $params) {
    configureRestoreEngine($params);
    $driver = $makeDriver();
    $service = makeRestoreService();

    $database = $params['database'];
    $tmpName = $database.'_restore_tmp';
    restoreDropIfExists($driver, $tmpName);

    $root = DB::connection('target_root');
    $root->statement('drop table if exists widgets'.($params['driver'] === 'pgsql' ? ' cascade' : ''));
    $root->statement('create table widgets (id int primary key)');
    $root->table('widgets')->insert(['id' => 1]);

    $badSql = $params['driver'] === 'pgsql'
        ? "create table x (id int);\ndrop database {$database};\n"
        : "CREATE TABLE x (id INT);\nDROP DATABASE {$database};\n";
    $archivePath = Storage::disk('backups')->path('bad_dump_'.bin2hex(random_bytes(6)).'.sql');
    file_put_contents($archivePath, $badSql);

    $backup = Backup::create([
        'database' => $database, 'format' => 'sql', 'path' => basename($archivePath),
        'source' => 'upload', 'status' => 'success', 'on_local' => true,
        'started_at' => now(), 'finished_at' => now(),
    ]);

    $restore = $service->prepareRestoreFromBackup($backup);

    expect(fn () => $service->restoreFromDump($restore->fresh()))
        ->toThrow(App\Backup\Exceptions\InvalidDumpException::class);

    $restore->refresh();
    expect($restore->status)->toBe('failed')
        ->and($restore->error_message)->not->toBeEmpty()
        ->and($driver->databaseExists($tmpName))->toBeFalse() // cleaned up, not left behind
        ->and($driver->databaseExists($database))->toBeTrue();

    // live is completely untouched
    $liveConn = restoreConnectAsRoot($params, $database);
    expect($liveConn->table('widgets')->count())->toBe(1);

    $root->statement('drop table if exists widgets'.($params['driver'] === 'pgsql' ? ' cascade' : ''));
})->with('restore_engines');

it('swaps an archive back in, archiving the current state under a new version', function ($makeDriver, array $params) {
    configureRestoreEngine($params);
    $driver = $makeDriver();
    $service = makeRestoreService();

    $database = $params['database'];
    $root = DB::connection('target_root');

    $root->statement('drop table if exists widgets'.($params['driver'] === 'pgsql' ? ' cascade' : ''));
    $root->statement('create table widgets (id int primary key)');
    $root->table('widgets')->insert(['id' => 1]);

    $archiveName = (new ArchiveNamer())->next($database, $driver->listDatabases($database));
    $driver->createDatabase($archiveName, like: $database);
    $archiveSeedConfig = config('database.connections.target_root');
    $archiveSeedConfig['database'] = $archiveName;
    config(['database.connections.swap_target' => $archiveSeedConfig]);
    DB::purge('swap_target');
    DB::connection('swap_target')->statement('create table widgets (id int primary key)');
    DB::connection('swap_target')->table('widgets')->insert(['id' => 999]);
    DB::purge('swap_target');

    $restore = $service->prepareSwapBack($database, $archiveName);
    $service->swapFromArchive($restore->fresh());
    $restore->refresh();

    expect($restore->status)->toBe('success')
        ->and($restore->archive_name)->not->toBe($archiveName); // a NEW archive - the old live's state

    $liveConn = restoreConnectAsRoot($params, $database);
    expect($liveConn->table('widgets')->pluck('id')->all())->toBe([999]);

    $newArchiveConn = restoreConnectAsRoot($params, $restore->archive_name);
    expect($newArchiveConn->table('widgets')->pluck('id')->all())->toBe([1]);

    DB::purge('swap_verify');
    restoreDropIfExists($driver, $restore->archive_name);
    $root->statement('drop table if exists widgets'.($params['driver'] === 'pgsql' ? ' cascade' : ''));
})->with('restore_engines');

it('refuses to swap back a database name that is not a recognized archive', function ($makeDriver, array $params) {
    configureRestoreEngine($params);
    $service = makeRestoreService();

    $restore = $service->prepareSwapBack($params['database'], 'not_a_real_archive_name');

    expect(fn () => $service->swapFromArchive($restore->fresh()))->toThrow(RuntimeException::class);

    $restore->refresh();
    expect($restore->status)->toBe('failed');
})->with('restore_engines');
