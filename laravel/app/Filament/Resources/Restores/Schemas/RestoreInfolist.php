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
            Section::make(__('Übersicht'))
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('kind')
                            ->label(__('Art'))
                            ->formatStateUsing(fn (string $state) => match ($state) {
                                'restore' => __('Wiederherstellung'),
                                'swap_back' => __('Zurücktauschen'),
                                default => $state,
                            }),

                        TextEntry::make('database')->label(__('Datenbank')),

                        TextEntry::make('status')
                            ->label(__('Status'))
                            ->badge()
                            ->formatStateUsing(fn (string $state) => match ($state) {
                                'pending' => __('Wartet'),
                                'running' => __('Läuft'),
                                'success' => __('Erfolgreich'),
                                'failed' => __('Fehlgeschlagen'),
                                default => $state,
                            })
                            ->color(fn (string $state) => match ($state) {
                                'pending' => 'gray',
                                'running' => 'warning',
                                'success' => 'success',
                                'failed' => 'danger',
                                default => 'gray',
                            }),

                        TextEntry::make('source_archive')->label(__('Quelle')),
                        TextEntry::make('tmp_database')->label(__('Temporäre Datenbank'))->default('—'),
                        TextEntry::make('archive_name')->label(__('Neues Archiv'))->default('—'),

                        TextEntry::make('triggeredBy.name')->label(__('Ausgelöst von'))->default('—'),
                        TextEntry::make('started_at')->label(__('Gestartet'))->dateTime('d.m.Y H:i:s'),
                        TextEntry::make('finished_at')->label(__('Beendet'))->dateTime('d.m.Y H:i:s')->default('—'),
                    ]),
                ]),

            Section::make(__('Fehlermeldung'))
                ->schema([
                    TextEntry::make('error_message')
                        ->hiddenLabel()
                        ->color('danger'),
                ])
                ->visible(fn ($record) => filled($record->error_message)),

            Section::make(__('Protokoll'))
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
