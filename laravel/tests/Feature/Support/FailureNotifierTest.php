<?php

use App\Mail\OperationFailedMail;
use App\Models\User;
use App\Support\FailureNotifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

it('sends a database notification to every user', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    app(FailureNotifier::class)->report('Backup', 'app', 'Something broke');

    expect($userA->fresh()->unreadNotifications()->count())->toBe(1)
        ->and($userB->fresh()->unreadNotifications()->count())->toBe(1)
        ->and($userA->fresh()->unreadNotifications()->first()->data['body'])->toContain('Something broke');
});

it('sends mail when BACKUP_NOTIFY_MAIL is configured', function () {
    Mail::fake();
    User::factory()->create();
    config(['backup.notify.mail' => 'admin@example.com']);

    app(FailureNotifier::class)->report('Backup', 'app', 'Disk full');

    Mail::assertSent(OperationFailedMail::class, function (OperationFailedMail $mail) {
        return $mail->hasTo('admin@example.com') && $mail->error === 'Disk full';
    });
});

it('does not send mail when BACKUP_NOTIFY_MAIL is empty', function () {
    Mail::fake();
    User::factory()->create();
    config(['backup.notify.mail' => null]);

    app(FailureNotifier::class)->report('Backup', 'app', 'Disk full');

    Mail::assertNothingSent();
});

it('posts a slack-shaped payload when configured', function () {
    Http::fake();
    User::factory()->create();
    config([
        'backup.notify.webhook_url' => 'https://hooks.slack.test/abc',
        'backup.notify.webhook_type' => 'slack',
    ]);

    app(FailureNotifier::class)->report('Backup', 'app', 'Disk full');

    Http::assertSent(fn ($request) => $request->url() === 'https://hooks.slack.test/abc'
        && str_contains($request['text'], 'Disk full'));
});

it('posts a discord-shaped payload when configured', function () {
    Http::fake();
    User::factory()->create();
    config([
        'backup.notify.webhook_url' => 'https://discord.test/abc',
        'backup.notify.webhook_type' => 'discord',
    ]);

    app(FailureNotifier::class)->report('Backup', 'app', 'Disk full');

    Http::assertSent(fn ($request) => str_contains($request['content'], 'Disk full'));
});

it('posts a generic payload for any other webhook type', function () {
    Http::fake();
    User::factory()->create();
    config([
        'backup.notify.webhook_url' => 'https://example.test/hook',
        'backup.notify.webhook_type' => 'generic',
    ]);

    app(FailureNotifier::class)->report('Backup', 'app', 'Disk full');

    Http::assertSent(fn ($request) => $request['event'] === 'Backup'
        && $request['database'] === 'app'
        && $request['error'] === 'Disk full');
});

it('does not call the webhook when no URL is configured', function () {
    Http::fake();
    User::factory()->create();
    config(['backup.notify.webhook_url' => null]);

    app(FailureNotifier::class)->report('Backup', 'app', 'Disk full');

    Http::assertNothingSent();
});

it('keeps sending the remaining channels even if the mail channel throws', function () {
    Http::fake();
    User::factory()->create();
    config([
        'backup.notify.mail' => 'not-a-real-mailer-target',
        'backup.notify.webhook_url' => 'https://example.test/hook',
        'backup.notify.webhook_type' => 'generic',
        'mail.default' => 'failover', // an unconfigured/broken mailer to force an exception, not a fake
        'mail.mailers.failover.transport' => 'failover',
        'mail.mailers.failover.mailers' => [],
    ]);

    app(FailureNotifier::class)->report('Backup', 'app', 'Disk full');

    Http::assertSent(fn ($request) => $request['event'] === 'Backup');
});
