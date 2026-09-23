<?php

namespace App\Filament\Pages\Actions;

use App\Backup\OperationLock;
use App\Jobs\DropArchiveJob;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

class DeleteArchiveAction
{
    public static function make(string $database): Action
    {
        return Action::make('delete')
            ->label('Löschen')
            ->icon(Heroicon::Trash)
            ->color('danger')
            ->disabled(fn () => app(OperationLock::class)->isHeld())
            ->schema(fn (array $record) => [
                TextInput::make('confirm')
                    ->label("Zum Bestätigen \"{$record['name']}\" eingeben")
                    ->placeholder($record['name'])
                    ->required()
                    ->rule(fn () => function (string $attribute, $value, $fail) use ($record) {
                        if ($value !== $record['name']) {
                            $fail('Der eingegebene Name stimmt nicht überein.');
                        }
                    }),
            ])
            ->modalDescription('Die archivierte Datenbank wird endgültig gelöscht.')
            ->action(function (array $record) use ($database) {
                DropArchiveJob::dispatch($database, $record['name']);

                Notification::make()
                    ->title('Löschen wurde eingereiht')
                    ->success()
                    ->send();
            });
    }
}
