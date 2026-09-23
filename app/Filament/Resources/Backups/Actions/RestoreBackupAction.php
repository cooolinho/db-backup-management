<?php

namespace App\Filament\Resources\Backups\Actions;

use App\Backup\OperationLock;
use App\Backup\RestoreService;
use App\Jobs\RestoreBackupJob;
use App\Models\Backup;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;

/**
 * Restore only ever works from the local file (never S3 - see the plan's
 * "Restore-Quelle" decision), so this is only visible for a successful,
 * locally-stored backup.
 */
class RestoreBackupAction
{
    public static function make(): Action
    {
        return Action::make('restore')
            ->label(__('Wiederherstellen'))
            ->icon(Heroicon::ArrowUturnLeft)
            ->color('warning')
            ->visible(fn (Backup $record) => $record->isSuccessful() && $record->on_local)
            ->disabled(fn () => app(OperationLock::class)->isHeld())
            ->schema(fn (Backup $record) => [
                Text::make(__('Die Datenbank [:database] wird ersetzt. Ihr aktueller Inhalt bleibt als neues Archiv erhalten.', ['database' => $record->database]))
                    ->color('warning'),
                TextInput::make('confirm')
                    ->label(__('Zum Bestätigen ":database" eingeben', ['database' => $record->database]))
                    ->placeholder($record->database)
                    ->required()
                    ->rule(fn () => function (string $attribute, $value, $fail) use ($record) {
                        if ($value !== $record->database) {
                            $fail(__('Der eingegebene Name stimmt nicht überein.'));
                        }
                    }),
            ])
            ->action(function (Backup $record) {
                $restore = app(RestoreService::class)->prepareRestoreFromBackup($record, auth()->id());
                RestoreBackupJob::dispatch($restore->id);

                Notification::make()
                    ->title(__('Wiederherstellung wurde eingereiht'))
                    ->body(__('Der Fortschritt ist unter "Wiederherstellungen" zu sehen.'))
                    ->success()
                    ->send();
            });
    }
}
