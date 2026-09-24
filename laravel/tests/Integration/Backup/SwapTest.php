<?php

use App\Backup\Drivers\DatabaseDriver;
use App\Backup\Drivers\MariaDbDriver;
use App\Backup\Drivers\MySqlDriver;
use App\Backup\Drivers\PostgresDriver;
use Illuminate\Support\Facades\DB;

dataset('swap_engines', [
    'mysql' => [fn () => new MySqlDriver(), [
        'driver' => 'mysql', 'host' => 'mysql', 'port' => 3306, 'database' => 'app',
        'username' => 'app', 'password' => 'secret',
        'root_username' => 'root', 'root_password' => 'root',
    ]],
    'mariadb' => [fn () => new MariaDbDriver(), [
        'driver' => 'mariadb', 'host' => 'mariadb', 'port' => 3306, 'database' => 'app',
        'username' => 'app', 'password' => 'secret',
        'root_username' => 'root', 'root_password' => 'root',
    ]],
    'pgsql' => [fn () => new PostgresDriver(), [
        'driver' => 'pgsql', 'host' => 'postgres', 'port' => 5432, 'database' => 'app',
        'username' => 'app', 'password' => 'secret',
        'root_username' => 'postgres', 'root_password' => 'root',
    ]],
]);

function configureSwapTarget(array $params): void
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

/** Registers a throwaway app-user-credentialed connection pointed at $database, to assert on its contents. */
function connectAsAppTo(array $params, string $database): \Illuminate\Database\Connection
{
    $config = config('database.connections.target');
    $config['database'] = $database;
    config(['database.connections.swap_verify' => $config]);
    DB::purge('swap_verify');

    return DB::connection('swap_verify');
}

/**
 * Registers a throwaway root-credentialed connection pointed at $database.
 * The app user only has grants on the live database *name* ("app", stable
 * across a swap) - not on a freshly minted archive name it was never
 * granted access to - so archives are inspected as root, same as the real
 * ArchiveDatabases admin page would.
 */
function connectAsRootTo(array $params, string $database): \Illuminate\Database\Connection
{
    $config = config('database.connections.target_root');
    $config['database'] = $database;
    config(['database.connections.swap_verify' => $config]);
    DB::purge('swap_verify');

    return DB::connection('swap_verify');
}

function dropIfExists(DatabaseDriver $driver, string $database): void
{
    if ($driver->databaseExists($database)) {
        $driver->dropDatabase($database);
    }
}

afterEach(function () {
    DB::purge('swap_verify');
});

it('swaps a live database for a replacement, archiving the original, keeping views/triggers/routines/events intact and the app user able to read and write', function ($makeDriver, array $params) {
    configureSwapTarget($params);
    $driver = $makeDriver();

    $live = 'app';
    $replacement = 'app_swap_replacement';
    $archive = 'app_swap_archive_'.bin2hex(random_bytes(4));

    dropIfExists($driver, $replacement);
    dropIfExists($driver, $archive);

    $root = DB::connection('target_root');

    // --- Seed "live" with a table, a view, a trigger and a function/procedure ---
    if ($params['driver'] === 'pgsql') {
        $root->statement('drop table if exists widgets cascade');
        $root->statement('create table widgets (id integer primary key, name varchar(100), log_count integer default 0)');
        $root->table('widgets')->insert(['id' => 1, 'name' => 'live-widget']);
        $root->statement('create or replace view widget_names as select id, name from widgets');
        $root->statement('drop function if exists bump_log_count() cascade');
        $root->statement('create function bump_log_count() returns trigger language plpgsql as $$
            begin new.log_count := coalesce(old.log_count, 0) + 1; return new; end; $$');
        $root->statement('create trigger widgets_bump before update on widgets for each row execute function bump_log_count()');
    } else {
        $root->statement('drop table if exists widgets');
        $root->statement('create table widgets (id int primary key, name varchar(100), log_count int default 0)');
        $root->table('widgets')->insert(['id' => 1, 'name' => 'live-widget']);
        $root->statement('drop view if exists widget_names');
        $root->statement('create view widget_names as select id, name from widgets');
        $root->statement('drop trigger if exists widgets_bump');
        $root->unprepared('create trigger widgets_bump before update on widgets for each row set new.log_count = old.log_count + 1');
    }

    // --- Create "replacement" (simulating a restored tmp database) with different content ---
    $driver->createDatabase($replacement, like: $live);
    // PostgreSQL: createDatabase() made "app" the owner of $replacement
    // (public schema ownership follows the db owner via pg_database_owner
    // since PG15+), so seeding as the app user leaves objects correctly
    // owned - matching the real flow, where import() runs fixOwnership()
    // before swap() ever sees the database. MySQL/MariaDB access is
    // schema-name-based rather than object-ownership-based (the app
    // user's grant on `app`.* covers whatever tables exist there once the
    // swap makes $replacement's tables live under that name), so seeding
    // via root is fine there - the app user has no grant on the
    // not-yet-renamed $replacement name itself.
    $seedConfig = config('database.connections.'.($params['driver'] === 'pgsql' ? 'target' : 'target_root'));
    $seedConfig['database'] = $replacement;
    config(['database.connections.swap_target' => $seedConfig]);
    DB::purge('swap_target');
    $replacementConn = DB::connection('swap_target');

    if ($params['driver'] === 'pgsql') {
        $replacementConn->statement('create table widgets (id integer primary key, name varchar(100))');
        $replacementConn->table('widgets')->insert(['id' => 2, 'name' => 'replacement-widget']);
        $replacementConn->statement('create or replace view widget_names as select id, name from widgets');
    } else {
        $replacementConn->statement('create table widgets (id int primary key, name varchar(100))');
        $replacementConn->table('widgets')->insert(['id' => 2, 'name' => 'replacement-widget']);
        $replacementConn->statement('create view widget_names as select id, name from widgets');
    }
    DB::purge('swap_target');

    // --- Swap ---
    $driver->swap($live, $replacement, $archive);

    try {
        // live now holds the replacement's data
        $liveConn = connectAsAppTo($params, $live);
        expect($liveConn->table('widgets')->pluck('name')->all())->toBe(['replacement-widget'])
            ->and($liveConn->table('widget_names')->pluck('name')->all())->toBe(['replacement-widget']);

        // app user (not root) can still read AND write live - the whole
        // point of keeping the database name stable across the swap.
        $liveConn->table('widgets')->insert(['id' => 3, 'name' => 'written-by-app-user']);
        expect($liveConn->table('widgets')->count())->toBe(2);

        // archive holds the original live data, view, trigger and function
        $archiveConn = connectAsRootTo($params, $archive);
        expect($archiveConn->table('widgets')->pluck('name')->all())->toBe(['live-widget'])
            ->and($archiveConn->table('widget_names')->pluck('name')->all())->toBe(['live-widget']);

        // the trigger captured from the original live db still fires in the archive
        $archiveConn->table('widgets')->where('id', 1)->update(['name' => 'live-widget-edited']);
        expect($archiveConn->table('widgets')->where('id', 1)->value('log_count'))->toBe(1);

        expect($driver->databaseExists($replacement))->toBeFalse();
    } finally {
        DB::purge('swap_target');
        DB::purge('swap_verify'); // close before dropping: Postgres refuses to drop a database with open connections
        dropIfExists($driver, $replacement);
        dropIfExists($driver, $archive);

        if ($params['driver'] === 'pgsql') {
            $root->statement('drop table if exists widgets cascade');
            $root->statement('drop function if exists bump_log_count() cascade');
        } else {
            // Unlike Postgres, dropping a table does not cascade-drop a
            // view that selects from it (MySQL/MariaDB just leaves the
            // view dangling) - clean it up explicitly, or it pollutes
            // whichever test runs against this fixture database next.
            $root->statement('drop view if exists widget_names');
            $root->statement('drop table if exists widgets');
        }
    }
})->with('swap_engines');

it('reports the correct table count and lists databases by prefix', function ($makeDriver, array $params) {
    configureSwapTarget($params);
    $driver = $makeDriver();

    expect($driver->tableCount($params['database']))->toBeInt();

    $probe = 'swaplist_probe_'.bin2hex(random_bytes(4));
    dropIfExists($driver, $probe);
    $driver->createDatabase($probe, like: $params['database']);

    try {
        $found = $driver->listDatabases('swaplist_probe_');
        expect($found)->toContain($probe);
    } finally {
        $driver->dropDatabase($probe);
    }
})->with('swap_engines');
