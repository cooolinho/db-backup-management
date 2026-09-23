<?php

use App\Backup\ArchiveFormatter;

beforeEach(function () {
    $this->formatter = new ArchiveFormatter();
    $this->workDir = sys_get_temp_dir().'/archive_formatter_test_'.bin2hex(random_bytes(6));
    mkdir($this->workDir);
});

afterEach(function () {
    foreach (glob("{$this->workDir}/*") ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($this->workDir);
});

it('packs and unpacks each format with the content intact', function (string $format) {
    $original = "-- dump\nCREATE TABLE widgets (id int);\nINSERT INTO widgets VALUES (1);\n";
    $rawSqlPath = "{$this->workDir}/raw.sql";
    file_put_contents($rawSqlPath, $original);

    $archivePath = $this->formatter->pack($rawSqlPath, "{$this->workDir}/app_20260101", $format, 'app.sql');

    expect($archivePath)->toEndWith(".{$format}")
        ->and(file_exists($archivePath))->toBeTrue()
        ->and(file_exists($rawSqlPath))->toBeFalse(); // pack() consumes the raw file

    $restoredPath = "{$this->workDir}/restored.sql";
    $this->formatter->unpack($archivePath, $restoredPath);

    expect(file_get_contents($restoredPath))->toBe($original);
})->with(ArchiveFormatter::FORMATS);

it('detects the format from a path', function () {
    expect($this->formatter->detectFormat('/backups/app_2026.sql'))->toBe('sql')
        ->and($this->formatter->detectFormat('/backups/app_2026.sql.gz'))->toBe('sql.gz')
        ->and($this->formatter->detectFormat('/backups/app_2026.zip'))->toBe('zip')
        ->and($this->formatter->detectFormat('/backups/app_2026.tar.gz'))->toBe('tar.gz');
});

it('rejects a path with no recognizable format', function () {
    $this->formatter->detectFormat('/backups/app_2026.bak');
})->throws(InvalidArgumentException::class);
