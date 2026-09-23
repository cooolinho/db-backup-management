<?php

use App\Filament\Resources\Backups\Pages\ListBackups;
use App\Jobs\ValidateUploadedDumpJob;
use App\Models\Backup;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('backups');
    $this->actingAs(User::factory()->create());
});

it('registers an uploaded dump as a validating backup and queues validation', function () {
    Bus::fake();

    $file = UploadedFile::fake()->createWithContent('my-dump.sql', "CREATE TABLE widgets (id int);\n");

    Livewire::test(ListBackups::class)
        ->callTableAction('uploadDump', data: ['file' => $file])
        ->assertHasNoTableActionErrors();

    $backup = Backup::sole();

    expect($backup->status)->toBe('validating')
        ->and($backup->source)->toBe('upload')
        ->and($backup->format)->toBe('sql')
        ->and($backup->on_local)->toBeTrue()
        ->and($backup->path)->toStartWith('upload_')
        ->and($backup->path)->toEndWith('.sql');

    Storage::disk('backups')->assertExists($backup->path);

    Bus::assertDispatched(ValidateUploadedDumpJob::class, fn ($job) => $job->backupId === $backup->id);
});

it('detects the format from the original filename for each supported extension', function (string $filename, string $expectedFormat) {
    Bus::fake();

    $file = UploadedFile::fake()->createWithContent($filename, 'dummy content');

    Livewire::test(ListBackups::class)
        ->callTableAction('uploadDump', data: ['file' => $file])
        ->assertHasNoTableActionErrors();

    expect(Backup::sole()->format)->toBe($expectedFormat);
})->with([
    ['dump.sql', 'sql'],
    ['dump.sql.gz', 'sql.gz'],
    ['dump.zip', 'zip'],
    ['dump.tar.gz', 'tar.gz'],
]);

it('rejects a file with an unsupported extension', function () {
    $file = UploadedFile::fake()->createWithContent('dump.bak', 'dummy');

    Livewire::test(ListBackups::class)
        ->callTableAction('uploadDump', data: ['file' => $file])
        ->assertHasTableActionErrors();

    expect(Backup::count())->toBe(0);
});

it('validates an uploaded dump and marks it successful', function () {
    $file = UploadedFile::fake()->createWithContent('my-dump.sql', "CREATE TABLE widgets (id int);\n");

    Livewire::test(ListBackups::class)
        ->callTableAction('uploadDump', data: ['file' => $file]);

    $backup = Backup::sole();
    expect($backup->status)->toBe('success')
        ->and($backup->sha256)->toHaveLength(64);
});

it('marks an uploaded dump failed when it contains a forbidden statement', function () {
    $file = UploadedFile::fake()->createWithContent('evil.sql', "CREATE TABLE t (id int);\nDROP DATABASE app;\n");

    Livewire::test(ListBackups::class)
        ->callTableAction('uploadDump', data: ['file' => $file]);

    $backup = Backup::sole();
    expect($backup->status)->toBe('failed')
        ->and($backup->error_message)->not->toBeEmpty();

    // the file itself is kept (not deleted) so the uploader can inspect it
    Storage::disk('backups')->assertExists($backup->path);
});
