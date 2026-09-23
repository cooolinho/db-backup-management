<?php

namespace App\Filament\Resources\Restores\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;

class RestoreInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Übersicht')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('kind')
                            ->label('Art')
                            ->formatStateUsing(fn (string $state) => match ($state) {
                                'restore' => 'Wiederherstellung',
                                'swap_back' => 'Zurücktauschen',
                                default => $state,
                            }),

                        TextEntry::make('database')->label('Datenbank'),

                        TextEntry::make('status')
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

                        TextEntry::make('source_archive')->label('Quelle'),
                        TextEntry::make('tmp_database')->label('Temporäre Datenbank')->default('—'),
                        TextEntry::make('archive_name')->label('Neues Archiv')->default('—'),

                        TextEntry::make('triggeredBy.name')->label('Ausgelöst von')->default('—'),
                        TextEntry::make('started_at')->label('Gestartet')->dateTime('d.m.Y H:i:s'),
                        TextEntry::make('finished_at')->label('Beendet')->dateTime('d.m.Y H:i:s')->default('—'),
                    ]),
                ]),

            Section::make('Fehlermeldung')
                ->schema([
                    TextEntry::make('error_message')
                        ->hiddenLabel()
                        ->color('danger'),
                ])
                ->visible(fn ($record) => filled($record->error_message)),

            Section::make('Protokoll')
                ->schema([
                    TextEntry::make('log')
                        ->hiddenLabel()
                        ->fontFamily(FontFamily::Mono)
                        ->default('—')
                        ->prose(),
                ]),
        ]);
    }
}
