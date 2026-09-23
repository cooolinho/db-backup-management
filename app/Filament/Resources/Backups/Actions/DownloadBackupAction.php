<?php

namespace App\Filament\Resources\Backups\Actions;

use App\Models\Backup;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadBackupAction
{
    public static function make(): Action
    {
        return Action::make('download')
            ->label('Herunterladen')
            ->icon(Heroicon::ArrowDownTray)
            ->visible(fn (Backup $record) => $record->isSuccessful() && ($record->on_local || $record->on_s3))
            ->action(function (Backup $record): StreamedResponse {
                $disk = $record->on_local ? 'backups' : 's3';

                abort_unless(
                    $record->path && Storage::disk($disk)->exists($record->path),
                    404,
                    'Backup-Datei nicht gefunden.',
                );

                return response()->streamDownload(function () use ($record, $disk) {
                    $stream = Storage::disk($disk)->readStream($record->path);
                    fpassthru($stream);
                    fclose($stream);
                }, basename($record->path));
            });
    }
}
