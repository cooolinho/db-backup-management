<?php

namespace App\Filament\Resources\Backups\Tables;

use App\Filament\Resources\Backups\Actions\CreateBackupAction;
use App\Filament\Resources\Backups\Actions\DeleteBackupAction;
use App\Filament\Resources\Backups\Actions\DownloadBackupAction;
use App\Filament\Resources\Backups\Actions\RestoreBackupAction;
use App\Filament\Resources\Backups\Actions\UploadDumpAction;
use App\Models\Backup;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BackupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->poll('5s')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('database')
                    ->label(__('Datenbank'))
                    ->searchable(),

                TextColumn::make('format')
                    ->label(__('Format'))
                    ->badge()
                    ->color('gray'),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'running' => __('Läuft'),
                        'validating' => __('Wird geprüft'),
                        'success' => __('Erfolgreich'),
                        'failed' => __('Fehlgeschlagen'),
                        default => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        'running', 'validating' => 'warning',
                        'success' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('source')
                    ->label(__('Auslöser'))
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'scheduled' => __('Geplant'),
                        'manual' => __('Manuell'),
                        'upload' => __('Hochgeladen'),
                        'cli' => __('CLI'),
                        default => $state,
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('size_bytes')
                    ->label(__('Größe'))
                    ->formatStateUsing(fn (?int $state) => self::humanSize($state))
                    ->alignEnd(),

                IconColumn::make('on_local')
                    ->label(__('Lokal'))
                    ->boolean(),

                IconColumn::make('on_s3')
                    ->label(__('S3'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('started_at')
                    ->label(__('Gestartet'))
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                TextColumn::make('duration')
                    ->label(__('Dauer'))
                    ->state(fn (Backup $record) => self::duration($record))
                    ->alignEnd(),
            ])
            ->recordActions([
                DownloadBackupAction::make(),
                RestoreBackupAction::make(),
                DeleteBackupAction::make(),
            ])
            ->toolbarActions([
                CreateBackupAction::make(),
                UploadDumpAction::make(),
            ])
            ->emptyStateHeading(__('Noch keine Backups'))
            ->emptyStateDescription(__('Erstelle das erste Backup, oder warte auf den nächsten geplanten Lauf.'));
    }

    private static function humanSize(?int $bytes): string
    {
        if ($bytes === null) {
            return '—';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 1).' '.$units[$i];
    }

    private static function duration(Backup $record): string
    {
        if (! $record->started_at) {
            return '—';
        }

        $end = $record->finished_at ?? now();

        return $record->started_at->diffForHumans($end, syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE, short: true);
    }
}
