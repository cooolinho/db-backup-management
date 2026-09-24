<?php

use App\Backup\Exceptions\OperationInProgressException;
use App\Jobs\CreateBackupJob;
use App\Jobs\DropArchiveJob;
use App\Jobs\RestoreBackupJob;
use App\Jobs\SwapArchiveJob;
use App\Models\Restore;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake();
    User::factory()->create();
    config([
        'backup.notify.webhook_url' => 'https://example.test/hook',
        'backup.notify.webhook_type' => 'generic',
    ]);
});

it('notifies on a real backup failure', function () {
    (new CreateBackupJob('manual'))->failed(new RuntimeException('boom'));

    Http::assertSent(fn ($r) => $r['event'] === 'Backup' && $r['error'] === 'boom');
});

it('does not notify when a backup job fails only due to a lock conflict', function () {
    (new CreateBackupJob('manual'))->failed(new OperationInProgressException('busy'));

    Http::assertNothingSent();
});

it('notifies on a real restore failure with the restore\'s database', function () {
    $restore = Restore::create(['kind' => 'restore', 'database' => 'app', 'status' => 'failed']);

    (new RestoreBackupJob($restore->id))->failed(new RuntimeException('bad dump'));

    Http::assertSent(fn ($r) => $r['event'] === 'Wiederherstellung' && $r['database'] === 'app' && $r['error'] === 'bad dump');
});

it('does not notify when a restore job fails only due to a lock conflict', function () {
    $restore = Restore::create(['kind' => 'restore', 'database' => 'app', 'status' => 'failed']);

    (new RestoreBackupJob($restore->id))->failed(new OperationInProgressException('busy'));

    Http::assertNothingSent();
});

it('notifies on a real swap-back failure', function () {
    $restore = Restore::create(['kind' => 'swap_back', 'database' => 'app', 'status' => 'failed']);

    (new SwapArchiveJob($restore->id))->failed(new RuntimeException('rename failed'));

    Http::assertSent(fn ($r) => $r['event'] === 'Zurücktauschen' && $r['database'] === 'app');
});

it('notifies on a real drop-archive failure', function () {
    (new DropArchiveJob('app', 'app_20260101_0300_v1'))->failed(new RuntimeException('in use'));

    Http::assertSent(fn ($r) => $r['event'] === 'Löschen des Archivs' && $r['database'] === 'app');
});

it('does not notify when a drop-archive job fails only due to a lock conflict', function () {
    (new DropArchiveJob('app', 'app_20260101_0300_v1'))->failed(new OperationInProgressException('busy'));

    Http::assertNothingSent();
});
