<?php

use App\Backup\DumpValidator;
use App\Backup\Exceptions\InvalidDumpException;

beforeEach(function () {
    $this->validator = new DumpValidator();
    $this->file = sys_get_temp_dir().'/dump_validator_test_'.bin2hex(random_bytes(6)).'.sql';
});

afterEach(function () {
    @unlink($this->file);
});

function writeDump(string $path, string $content): void
{
    file_put_contents($path, $content);
}

it('accepts a plain mysql-family dump with no database statements', function () {
    writeDump($this->file, "-- MySQL dump\nCREATE TABLE `widgets` (`id` int) ENGINE=InnoDB;\nINSERT INTO `widgets` VALUES (1);\n");

    $this->validator->validate($this->file, 'mysql');
    $this->validator->validate($this->file, 'mariadb');
})->throwsNoExceptions();

it('accepts a plain postgres dump with no database statements', function () {
    writeDump($this->file, "-- PostgreSQL dump\nCREATE TABLE widgets (id integer);\nCOPY widgets (id) FROM stdin;\n1\n\\.\n");

    $this->validator->validate($this->file, 'pgsql');
})->throwsNoExceptions();

it('rejects CREATE DATABASE / DROP DATABASE / USE for mysql-family dumps', function (string $line) {
    writeDump($this->file, "CREATE TABLE t (id int);\n{$line}\n");

    $this->validator->validate($this->file, 'mysql');
})->with([
    'CREATE DATABASE evil;',
    'DROP DATABASE app;',
    'CREATE SCHEMA evil;',
    'USE mysql;',
])->throws(InvalidDumpException::class);

it('rejects \connect, \c, CREATE/DROP/ALTER DATABASE for postgres dumps', function (string $line) {
    writeDump($this->file, "CREATE TABLE t (id integer);\n{$line}\n");

    $this->validator->validate($this->file, 'pgsql');
})->with([
    '\connect postgres',
    '\c postgres',
    'CREATE DATABASE evil;',
    'DROP DATABASE app;',
    "ALTER DATABASE app RENAME TO stolen;",
])->throws(InvalidDumpException::class);

it('flags a postgres dump uploaded against a mysql-family target', function () {
    writeDump($this->file, "CREATE TABLE widgets (id integer);\nCOPY widgets (id) FROM stdin;\n");

    $this->validator->validate($this->file, 'mysql');
})->throws(InvalidDumpException::class);

it('flags a mysql-family dump uploaded against a postgres target', function () {
    writeDump($this->file, "CREATE TABLE `widgets` (`id` int) ENGINE=InnoDB;\n");

    $this->validator->validate($this->file, 'pgsql');
})->throws(InvalidDumpException::class);

it('does not flag legitimate statements containing the word database or use as a column/identifier', function () {
    writeDump($this->file, "CREATE TABLE t (id int, `database_name` varchar(50), usecase varchar(10));\nINSERT INTO t VALUES (1, 'x', 'y');\n");

    $this->validator->validate($this->file, 'mysql');
})->throwsNoExceptions();
