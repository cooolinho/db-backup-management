<?php

namespace App\Filament\Resources\Backups\Tables;

use App\Filament\Resources\Backups\Actions\CreateBackupAction;
use App\Filament\Resources\Backups\Actions\DeleteBackupAction;
use App\Filament\Resources\Backups\Actions\DownloadBackupAction;
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
                    ->label('Datenbank')
                    ->searchable(),

                TextColumn::make('format')
                    ->label('Format')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'running' => 'Läuft',
                        'success' => 'Erfolgreich',
                        'failed' => 'Fehlgeschlagen',
                        default => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        'running' => 'warning',
                        'success' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('source')
                    ->label('Auslöser')
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'scheduled' => 'Geplant',
                        'manual' => 'Manuell',
                        'upload' => 'Hochgeladen',
                        'cli' => 'CLI',
                        default => $state,
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('size_bytes')
                    ->label('Größe')
                    ->formatStateUsing(fn (?int $state) => self::humanSize($state))
                    ->alignEnd(),

                IconColumn::make('on_local')
                    ->label('Lokal')
                    ->boolean(),

                IconColumn::make('on_s3')
                    ->label('S3')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('started_at')
                    ->label('Gestartet')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                TextColumn::make('duration')
                    ->label('Dauer')
                    ->state(fn (Backup $record) => self::duration($record))
                    ->alignEnd(),
            ])
            ->recordActions([
                DownloadBackupAction::make(),
                DeleteBackupAction::make(),
            ])
            ->toolbarActions([
                CreateBackupAction::make(),
            ])
            ->emptyStateHeading('Noch keine Backups')
            ->emptyStateDescription('Erstelle das erste Backup, oder warte auf den nächsten geplanten Lauf.');
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
