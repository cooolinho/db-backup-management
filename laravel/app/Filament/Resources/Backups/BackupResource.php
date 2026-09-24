<?php

namespace App\Filament\Resources\Backups;

use App\Filament\Resources\Backups\Pages\ListBackups;
use App\Filament\Resources\Backups\Tables\BackupsTable;
use App\Models\Backup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class BackupResource extends Resource
{
    protected static ?string $model = Backup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return __('Backups');
    }

    public static function getModelLabel(): string
    {
        return __('Backup');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Backups');
    }

    // Backups are created by a header action (dispatching CreateBackupJob),
    // not through a standard Filament create form.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return BackupsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBackups::route('/'),
        ];
    }
}
