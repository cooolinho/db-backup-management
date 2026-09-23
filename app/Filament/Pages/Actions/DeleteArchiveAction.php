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
            ->label(__('Löschen'))
            ->icon(Heroicon::Trash)
            ->color('danger')
            ->disabled(fn () => app(OperationLock::class)->isHeld())
            ->schema(fn (array $record) => [
                TextInput::make('confirm')
                    ->label(__('Zum Bestätigen ":name" eingeben', ['name' => $record['name']]))
                    ->placeholder($record['name'])
                    ->required()
                    ->rule(fn () => function (string $attribute, $value, $fail) use ($record) {
                        if ($value !== $record['name']) {
                            $fail(__('Der eingegebene Name stimmt nicht überein.'));
                        }
                    }),
            ])
            ->modalDescription(__('Die archivierte Datenbank wird endgültig gelöscht.'))
            ->action(function (array $record) use ($database) {
                DropArchiveJob::dispatch($database, $record['name'], auth()->id());

                Notification::make()
                    ->title(__('Löschen wurde eingereiht'))
                    ->success()
                    ->send();
            });
    }
}
