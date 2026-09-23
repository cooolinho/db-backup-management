<?php

use App\Backup\Drivers\MariaDbDriver;
use App\Backup\Drivers\MySqlDriver;
use App\Backup\Drivers\PostgresDriver;
use App\Backup\DriverFactory;

it('maps DB_CONNECTION values to the matching driver', function (string $connection, string $expected) {
    expect((new DriverFactory())->make($connection))->toBeInstanceOf($expected);
})->with([
    ['mysql', MySqlDriver::class],
    ['mariadb', MariaDbDriver::class],
    ['pgsql', PostgresDriver::class],
]);

it('falls back to config(backup.driver) when no driver is given', function () {
    config(['backup.driver' => 'pgsql']);

    expect((new DriverFactory())->make())->toBeInstanceOf(PostgresDriver::class);
});

it('rejects an unsupported driver name', function () {
    (new DriverFactory())->make('sqlsrv');
})->throws(InvalidArgumentException::class);
