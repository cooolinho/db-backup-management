<?php

namespace App\Filament\Resources\Restores\Tables;

use App\Models\Restore;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RestoresTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->poll('3s')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('kind')
                    ->label('Art')
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'restore' => 'Wiederherstellung',
                        'swap_back' => 'Zurücktauschen',
                        default => $state,
                    })
                    ->badge()
                    ->color('gray'),

                TextColumn::make('database')
                    ->label('Datenbank')
                    ->searchable(),

                TextColumn::make('archive_name')
                    ->label('Archiv')
                    ->toggleable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'pending' => 'Wartet',
                        'running' => 'Läuft',
                        'success' => 'Erfolgreich',
                        'failed' => 'Fehlgeschlagen',
                        default => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        'pending' => 'gray',
                        'running' => 'warning',
                        'success' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('step')
                    ->label('Schritt')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('triggeredBy.name')
                    ->label('Ausgelöst von')
                    ->default('—'),

                TextColumn::make('started_at')
                    ->label('Gestartet')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                TextColumn::make('duration')
                    ->label('Dauer')
                    ->state(fn (Restore $record) => self::duration($record))
                    ->alignEnd(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('Noch keine Wiederherstellungen');
    }

    private static function duration(Restore $record): string
    {
        if (! $record->started_at) {
            return '—';
        }

        $end = $record->finished_at ?? now();

        return $record->started_at->diffForHumans($end, syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE, short: true);
    }
}
