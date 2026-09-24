<?php

use App\Backup\Drivers\MariaDbDriver;
use App\Backup\Drivers\MySqlDriver;
use App\Backup\Drivers\PostgresDriver;
use Illuminate\Support\Facades\DB;

/**
 * One dataset entry per engine, pointed at the docker-compose.dev.yml
 * fixtures (reachable by service name when this test runs inside a
 * container attached to that compose network).
 */
dataset('engines', [
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

function configureTargetConnections(array $params): void
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

/** Registers a throwaway connection pointed at $database, using root credentials, to assert on its contents. */
function connectTo(array $params, string $database): \Illuminate\Database\Connection
{
    $config = config('database.connections.target_root');
    $config['database'] = $database;
    config(['database.connections.target_verify' => $config]);
    DB::purge('target_verify');

    return DB::connection('target_verify');
}

it('reports a server version', function ($makeDriver, array $params) {
    configureTargetConnections($params);

    expect($makeDriver())->serverVersion()->not->toBeEmpty();
})->with('engines');

it('detects whether a database exists', function ($makeDriver, array $params) {
    configureTargetConnections($params);
    $driver = $makeDriver();

    expect($driver->databaseExists('app'))->toBeTrue()
        ->and($driver->databaseExists('does_not_exist_xyz'))->toBeFalse();
})->with('engines');

it('creates and drops a database matching a reference database', function ($makeDriver, array $params) {
    configureTargetConnections($params);
    $driver = $makeDriver();
    $name = 'app_create_drop_test';

    if ($driver->databaseExists($name)) {
        $driver->dropDatabase($name);
    }

    $driver->createDatabase($name, like: 'app');

    expect($driver->databaseExists($name))->toBeTrue()
        ->and($driver->databaseSize($name))->toBeInt();

    $driver->dropDatabase($name);

    expect($driver->databaseExists($name))->toBeFalse();
})->with('engines');

it('dumps a database and imports it into a fresh one with the data intact', function ($makeDriver, array $params) {
    configureTargetConnections($params);
    $driver = $makeDriver();
    $importDb = 'app_dump_import_test';

    if ($driver->databaseExists($importDb)) {
        $driver->dropDatabase($importDb);
    }

    $target = DB::connection('target');
    $target->statement('drop table if exists widgets');
    $target->statement('create table widgets (id integer primary key, name varchar(100) not null)');
    $target->table('widgets')->insert(['id' => 1, 'name' => 'Grommet']);

    $dumpFile = tempnam(sys_get_temp_dir(), 'dbdump_').'.sql';

    try {
        $driver->dump('app', $dumpFile);
        expect(filesize($dumpFile))->toBeGreaterThan(0);

        $driver->createDatabase($importDb, like: 'app');
        $driver->import($importDb, $dumpFile);

        $row = connectTo($params, $importDb)->table('widgets')->where('id', 1)->first();

        expect($row)->not->toBeNull()
            ->and($row->name)->toBe('Grommet');
    } finally {
        @unlink($dumpFile);
        $target->statement('drop table if exists widgets');
        DB::purge('target_verify'); // close it first: Postgres refuses to drop a database with open connections
        if ($driver->databaseExists($importDb)) {
            $driver->dropDatabase($importDb);
        }
    }
})->with('engines');
