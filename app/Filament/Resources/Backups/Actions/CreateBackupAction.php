<?php

namespace App\Filament\Resources\Backups\Actions;

use App\Jobs\CreateBackupJob;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

class CreateBackupAction
{
    public static function make(): Action
    {
        return Action::make('createBackup')
            ->label('Backup jetzt erstellen')
            ->icon(Heroicon::Plus)
            ->action(function () {
                CreateBackupJob::dispatch(source: 'manual', triggeredBy: auth()->id());

                Notification::make()
                    ->title('Backup wurde eingereiht')
                    ->body('Es erscheint in der Liste, sobald es läuft.')
                    ->success()
                    ->send();
            });
    }
}
