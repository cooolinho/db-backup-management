<?php

use App\Backup\RetentionService;
use App\Models\Backup;
use Illuminate\Support\Facades\Storage;

function makeBackup(array $overrides = []): Backup
{
    static $n = 0;
    $n++;

    $backup = Backup::create(array_merge([
        'database' => 'app',
        'format' => 'sql.gz',
        'path' => "app_2026010{$n}.sql.gz",
        'source' => 'scheduled',
        'status' => 'success',
        'on_local' => true,
        'on_s3' => false,
        'finished_at' => now()->subDays(10 - $n),
    ], $overrides));

    if ($backup->on_local) {
        Storage::disk('backups')->put($backup->path, 'dummy');
    }
    if ($backup->on_s3) {
        Storage::disk('s3')->put($backup->path, 'dummy');
    }

    return $backup;
}

beforeEach(function () {
    Storage::fake('backups');
    Storage::fake('s3');
});

it('keeps only the newest N local backups and deletes the rest', function () {
    for ($i = 1; $i <= 5; $i++) {
        makeBackup();
    }

    (new RetentionService())->prune('app', keepLocal: 2, keepS3: -1);

    expect(Backup::where('on_local', true)->count())->toBe(2)
        ->and(Backup::count())->toBe(2); // the pruned rows had nothing left anywhere, so they're gone too
});

it('keeps local and S3 retention independent', function () {
    for ($i = 1; $i <= 4; $i++) {
        makeBackup(['on_s3' => true]);
    }

    (new RetentionService())->prune('app', keepLocal: 1, keepS3: 3);

    // Newest 1 kept locally, newest 3 kept on S3: rows 2-4 still exist
    // (on S3), row 1 lost its S3 copy too and is gone entirely.
    expect(Backup::count())->toBe(3)
        ->and(Backup::where('on_local', true)->count())->toBe(1)
        ->and(Backup::where('on_s3', true)->count())->toBe(3);
});

it('never deletes manually uploaded backups', function () {
    makeBackup(['source' => 'upload']);
    makeBackup();
    makeBackup();

    (new RetentionService())->prune('app', keepLocal: 1, keepS3: -1);

    expect(Backup::where('source', 'upload')->count())->toBe(1);
});

it('keeps everything when retention is negative', function () {
    for ($i = 1; $i <= 3; $i++) {
        makeBackup();
    }

    (new RetentionService())->prune('app', keepLocal: -1, keepS3: -1);

    expect(Backup::count())->toBe(3);
});
