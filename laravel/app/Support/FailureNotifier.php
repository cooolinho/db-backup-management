<?php

namespace App\Support;

use App\Mail\OperationFailedMail;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Reports an operational failure (backup/restore/swap/drop) through every
 * configured channel: a Filament database notification to every user, mail
 * to BACKUP_NOTIFY_MAIL, and a webhook to BACKUP_NOTIFY_WEBHOOK_URL - all
 * optional except the database one. Each channel is wrapped independently:
 * a broken mail/webhook config must never mask the original failure or
 * stop the other channels from firing.
 */
class FailureNotifier
{
    public function report(string $operation, string $database, string $error): void
    {
        $this->notifyDatabase($operation, $database, $error);
        $this->notifyMail($operation, $database, $error);
        $this->notifyWebhook($operation, $database, $error);
    }

    private function notifyDatabase(string $operation, string $database, string $error): void
    {
        try {
            foreach (User::all() as $user) {
                Notification::make()
                    ->danger()
                    ->title("{$operation} von [{$database}] fehlgeschlagen")
                    ->body($error)
                    ->sendToDatabase($user);
            }
        } catch (Throwable $e) {
            Log::warning("FailureNotifier: database notification failed: {$e->getMessage()}");
        }
    }

    private function notifyMail(string $operation, string $database, string $error): void
    {
        $to = config('backup.notify.mail');

        if (blank($to)) {
            return;
        }

        try {
            Mail::to($to)->send(new OperationFailedMail($operation, $database, $error));
        } catch (Throwable $e) {
            Log::warning("FailureNotifier: mail notification failed: {$e->getMessage()}");
        }
    }

    private function notifyWebhook(string $operation, string $database, string $error): void
    {
        $url = config('backup.notify.webhook_url');

        if (blank($url)) {
            return;
        }

        try {
            Http::timeout(10)->post($url, $this->webhookPayload($operation, $database, $error));
        } catch (Throwable $e) {
            Log::warning("FailureNotifier: webhook notification failed: {$e->getMessage()}");
        }
    }

    private function webhookPayload(string $operation, string $database, string $error): array
    {
        return match (config('backup.notify.webhook_type')) {
            'slack' => [
                'text' => "*{$operation} von [{$database}] fehlgeschlagen*\n{$error}",
            ],
            'discord' => [
                'content' => "**{$operation} von [{$database}] fehlgeschlagen**\n{$error}",
            ],
            default => [
                'event' => $operation,
                'database' => $database,
                'error' => $error,
                'occurred_at' => now()->toIso8601String(),
                'url' => config('app.url'),
            ],
        };
    }
}
