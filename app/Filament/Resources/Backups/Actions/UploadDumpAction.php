<?php

namespace App\Filament\Resources\Backups\Actions;

use App\Backup\ArchiveFormatter;
use App\Backup\UploadedDumpRegistrar;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

class UploadDumpAction
{
    public static function make(): Action
    {
        return Action::make('uploadDump')
            ->label(__('Dump hochladen'))
            ->icon(Heroicon::ArrowUpTray)
            ->schema([
                FileUpload::make('file')
                    ->label(__('Dump-Datei (:formats)', ['formats' => implode(', ', ArchiveFormatter::FORMATS)]))
                    ->disk('backups')
                    ->directory('.incoming')
                    ->visibility('private')
                    ->preventFilePathTampering()
                    ->storeFileNamesIn('file_original_name')
                    ->maxSize(backup_upload_max_kb())
                    ->required()
                    ->rule(fn () => function (string $attribute, $value, $fail) {
                        try {
                            app(ArchiveFormatter::class)->detectFormat($value->getClientOriginalName());
                        } catch (\InvalidArgumentException $e) {
                            $fail($e->getMessage());
                        }
                    }),
            ])
            ->action(function (array $data) {
                $originalName = $data['file_original_name'] ?? basename($data['file']);

                $backup = app(UploadedDumpRegistrar::class)->register(
                    $data['file'],
                    $originalName,
                    source: 'upload',
                    triggeredBy: auth()->id(),
                );

                Notification::make()
                    ->title(__('Dump wird geprüft'))
                    ->body(__('Er erscheint als "Wartet" in der Liste, bis die Prüfung abgeschlossen ist.'))
                    ->success()
                    ->send();
            });
    }
}
