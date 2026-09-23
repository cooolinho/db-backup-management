<?php

namespace App\Filament\Resources\AuditLogs\Schemas;

use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AuditLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Übersicht'))
                ->schema([
                    Grid::make(2)->schema([
                        TextEntry::make('created_at')->label(__('Zeitpunkt'))->dateTime('d.m.Y H:i:s'),
                        TextEntry::make('user.name')->label(__('Benutzer'))->default(__('System')),
                        TextEntry::make('action')->label(__('Aktion'))->badge()->color('gray'),
                        TextEntry::make('ip')->label(__('IP'))->default('—'),
                    ]),

                    TextEntry::make('description')
                        ->label(__('Beschreibung'))
                        ->columnSpanFull(),
                ]),

            Section::make(__('Details'))
                ->schema([
                    KeyValueEntry::make('properties')
                        ->hiddenLabel(),
                ])
                ->visible(fn ($record) => filled($record->properties)),
        ]);
    }
}
