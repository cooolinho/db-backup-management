<?php

namespace App\Filament\Pages\Actions;

use App\Backup\OperationLock;
use App\Backup\RestoreService;
use App\Jobs\SwapArchiveJob;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Swaps an archive back in as the live database. The current live content
 * isn't lost either - RestoreService::swapFromArchive() archives it under
 * a new version, same as any other swap.
 */
class SwapBackArchiveAction
{
    public static function make(string $database): Action
    {
        return Action::make('swapBack')
            ->label(__('Zurücktauschen'))
            ->icon(Heroicon::ArrowUturnLeft)
            ->color('warning')
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
            ->modalDescription(fn (array $record) => __('Die aktuelle Datenbank [:live] wird durch [:archive] ersetzt. Ihr bisheriger Inhalt bleibt als neues Archiv erhalten.', ['live' => $database, 'archive' => $record['name']]))
            ->action(function (array $record) use ($database) {
                $restore = app(RestoreService::class)->prepareSwapBack($database, $record['name'], auth()->id());
                SwapArchiveJob::dispatch($restore->id);

                Notification::make()
                    ->title(__('Zurücktauschen wurde eingereiht'))
                    ->success()
                    ->send();
            });
    }
}
