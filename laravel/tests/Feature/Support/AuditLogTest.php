<?php

use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\Backups\Pages\ListBackups;
use App\Models\AuditLog;
use App\Models\Backup;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('records a login, a logout and a failed login attempt', function () {
    // The login page is a Filament Livewire component, not a plain POST
    // form, so this dispatches the real Laravel auth events directly -
    // exactly what AppServiceProvider's listeners react to either way.
    $user = User::factory()->create();

    event(new \Illuminate\Auth\Events\Login('web', $user, false));
    expect(AuditLog::where('action', 'auth.login')->where('user_id', $user->id)->exists())->toBeTrue();

    event(new \Illuminate\Auth\Events\Logout('web', $user));
    expect(AuditLog::where('action', 'auth.logout')->where('user_id', $user->id)->exists())->toBeTrue();

    event(new \Illuminate\Auth\Events\Failed('web', null, ['email' => 'nobody@example.com', 'password' => 'x']));
    expect(AuditLog::where('action', 'auth.login_failed')->where('description', 'like', '%nobody@example.com%')->exists())->toBeTrue();
});

it('records a download, a delete and an upload', function () {
    Storage::fake('backups');
    $user = User::factory()->create();
    $this->actingAs($user);

    Storage::disk('backups')->put('a.sql.gz', 'dummy');
    $backup = Backup::create([
        'database' => 'app', 'format' => 'sql.gz', 'path' => 'a.sql.gz',
        'source' => 'manual', 'status' => 'success', 'on_local' => true,
    ]);

    Livewire::test(ListBackups::class)->callTableAction('download', $backup);
    expect(AuditLog::where('action', 'backup.downloaded')->where('subject_id', $backup->id)->exists())->toBeTrue();

    Livewire::test(ListBackups::class)->callTableAction('delete', $backup, data: []);
    expect(AuditLog::where('action', 'backup.deleted')->exists())->toBeTrue();

    $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('dump.sql', "CREATE TABLE t (id int);\n");
    Livewire::test(ListBackups::class)->callTableAction('uploadDump', data: ['file' => $file]);
    expect(AuditLog::where('action', 'backup.uploaded')->where('user_id', $user->id)->exists())->toBeTrue();
});

it('renders the audit log list page and a real entry', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    App\Support\Audit::record('backup.created', 'Backup #1 erstellt', properties: ['size_bytes' => 123]);

    Livewire::test(ListAuditLogs::class)
        ->assertSuccessful()
        ->assertSee('backup.created')
        ->assertSee('Backup #1 erstellt');
});

it('filters the audit log by action', function () {
    $this->actingAs(User::factory()->create());

    App\Support\Audit::record('backup.created', 'Backup A');
    App\Support\Audit::record('backup.deleted', 'Backup B');

    Livewire::test(ListAuditLogs::class)
        ->filterTable('action', 'backup.created')
        ->assertCanSeeTableRecords(AuditLog::where('action', 'backup.created')->get())
        ->assertCanNotSeeTableRecords(AuditLog::where('action', 'backup.deleted')->get());
});
