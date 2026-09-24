<?php

namespace App\Filament\Resources\Backups\Actions;

use App\Models\Backup;
use App\Support\Audit;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes the backup's underlying file(s) - local and/or S3 - along with
 * its row. Plain Eloquent deletion alone would silently orphan the file.
 */
class DeleteBackupAction
{
    public static function make(): Action
    {
        return Action::make('delete')
            ->label(__('Löschen'))
            ->icon(Heroicon::Trash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('Das Backup und alle gespeicherten Kopien (lokal und S3) werden endgültig gelöscht.'))
            ->visible(fn (Backup $record) => ! $record->isRunning())
            ->action(function (Backup $record) {
                if ($record->on_local && $record->path && Storage::disk('backups')->exists($record->path)) {
                    Storage::disk('backups')->delete($record->path);
                }

                if ($record->on_s3 && $record->path && Storage::disk('s3')->exists($record->path)) {
                    Storage::disk('s3')->delete($record->path);
                }

                Audit::record('backup.deleted', "Backup #{$record->id} von [{$record->database}] gelöscht", $record);

                $record->delete();

                Notification::make()
                    ->title(__('Backup gelöscht'))
                    ->success()
                    ->send();
            });
    }
}
