<?php

use App\Models\Backup;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('backups');
    config(['database.connections.target.driver' => 'mysql']);
});

it('imports and validates a local dump file synchronously', function () {
    $path = sys_get_temp_dir().'/import_test_'.bin2hex(random_bytes(6)).'.sql';
    file_put_contents($path, "CREATE TABLE widgets (id int);\n");

    $this->artisan('backup:import', ['path' => $path])->assertSuccessful();

    $backup = Backup::sole();
    expect($backup->status)->toBe('success')
        ->and($backup->source)->toBe('cli')
        ->and($backup->format)->toBe('sql');

    // --move not passed: the original file is left in place
    expect(file_exists($path))->toBeTrue();

    unlink($path);
});

it('removes the source file when --move is given', function () {
    $path = sys_get_temp_dir().'/import_test_'.bin2hex(random_bytes(6)).'.sql';
    file_put_contents($path, "CREATE TABLE widgets (id int);\n");

    $this->artisan('backup:import', ['path' => $path, '--move' => true])->assertSuccessful();

    expect(file_exists($path))->toBeFalse();
});

it('fails when the dump contains a forbidden statement', function () {
    $path = sys_get_temp_dir().'/import_test_'.bin2hex(random_bytes(6)).'.sql';
    file_put_contents($path, "CREATE TABLE t (id int);\nDROP DATABASE app;\n");

    $this->artisan('backup:import', ['path' => $path])->assertFailed();

    expect(Backup::sole()->status)->toBe('failed');

    unlink($path);
});

it('fails when the file does not exist', function () {
    $this->artisan('backup:import', ['path' => '/does/not/exist.sql'])->assertFailed();

    expect(Backup::count())->toBe(0);
});
