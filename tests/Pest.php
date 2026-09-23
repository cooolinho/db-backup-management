<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

/*
|--------------------------------------------------------------------------
| Integration tests
|--------------------------------------------------------------------------
|
| Talk to real database servers via docker/entrypoint-installed clients
| (mariadb-dump/mariadb, pg_dump/psql). Tagged so the default test run
| (composer test / php artisan test) excludes them via --exclude-group;
| run them explicitly against the docker-compose.dev.yml fixtures with:
|
|   docker compose -f docker-compose.dev.yml up -d mysql mariadb postgres
|   php artisan test --group=integration
|
*/
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Integration')
    ->group('integration');
