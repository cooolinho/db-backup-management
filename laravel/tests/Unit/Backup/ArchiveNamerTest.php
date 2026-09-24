<?php

use App\Backup\ArchiveNamer;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-09-23 14:05:00');
    $this->namer = new ArchiveNamer();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('builds a tmp name', function () {
    expect($this->namer->tmpName('app'))->toBe('app_restore_tmp');
});

it('builds the first archive name at version 1 when none exist yet', function () {
    expect($this->namer->next('app', []))->toBe('app_20260923_1405_v1');
});

it('picks the highest existing version and adds one, ignoring unrelated names', function () {
    $name = $this->namer->next('app', [
        'app_20260101_0300_v1',
        'app_20260215_0300_v3',
        'app_20260301_0300_v2',
        'app_other_thing',
        'otherapp_20260101_0300_v9', // different database, must be ignored
        'app_restore_tmp',
    ]);

    expect($name)->toBe('app_20260923_1405_v4');
});

it('recognizes only its own archives via isArchiveOf', function () {
    expect($this->namer->isArchiveOf('app', 'app_20260923_1405_v1'))->toBeTrue()
        ->and($this->namer->isArchiveOf('app', 'app_20260923_1405_v42'))->toBeTrue()
        ->and($this->namer->isArchiveOf('app', 'app'))->toBeFalse() // never the live database itself
        ->and($this->namer->isArchiveOf('app', 'app_restore_tmp'))->toBeFalse()
        ->and($this->namer->isArchiveOf('app', 'appxyz_20260923_1405_v1'))->toBeFalse()
        ->and($this->namer->isArchiveOf('app', 'otherapp_20260923_1405_v1'))->toBeFalse()
        ->and($this->namer->isArchiveOf('app', 'app_20260923_1405_v1; drop table users'))->toBeFalse();
});

it('parses timestamp and version back out of an archive name', function () {
    $parsed = $this->namer->parse('app', 'app_20260301_0930_v7');

    expect($parsed)->not->toBeNull()
        ->and($parsed['version'])->toBe(7)
        ->and($parsed['timestamp']->format('Y-m-d H:i'))->toBe('2026-03-01 09:30');
});

it('returns null when parsing a name that is not an archive of the given database', function () {
    expect($this->namer->parse('app', 'app'))->toBeNull();
});

it('truncates very long database names so archive/tmp names stay within 63 characters', function () {
    $longName = str_repeat('a', 55);

    $tmp = $this->namer->tmpName($longName);
    $archive = $this->namer->next($longName, []);

    expect(strlen($tmp))->toBeLessThanOrEqual(63)
        ->and(strlen($archive))->toBeLessThanOrEqual(63)
        ->and($this->namer->isArchiveOf($longName, $archive))->toBeTrue();
});
