<?php

namespace App\Filament\Resources\Restores;

use App\Filament\Resources\Restores\Pages\ListRestores;
use App\Filament\Resources\Restores\Pages\ViewRestore;
use App\Filament\Resources\Restores\Schemas\RestoreInfolist;
use App\Filament\Resources\Restores\Tables\RestoresTable;
use App\Models\Restore;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/** Read-only: a restore/swap-back is created by an action or the backup:restore CLI command, never through a form here. */
class RestoreResource extends Resource
{
    protected static ?string $model = Restore::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static ?string $navigationLabel = 'Wiederherstellungen';

    protected static ?string $modelLabel = 'Wiederherstellung';

    protected static ?string $pluralModelLabel = 'Wiederherstellungen';

    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return RestoresTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return RestoreInfolist::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRestores::route('/'),
            'view' => ViewRestore::route('/{record}'),
        ];
    }
}
