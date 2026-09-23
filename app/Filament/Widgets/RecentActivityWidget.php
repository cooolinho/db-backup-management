<?php

namespace App\Filament\Widgets;

use App\Models\AuditLog;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentActivityWidget extends BaseWidget
{
    protected static ?int $sort = 10;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Letzte Aktivität'))
            ->query(AuditLog::query()->latest('created_at')->limit(10))
            ->paginated(false)
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('Zeitpunkt'))
                    ->dateTime('d.m.Y H:i:s'),

                TextColumn::make('user.name')
                    ->label(__('Benutzer'))
                    ->default(__('System')),

                TextColumn::make('action')
                    ->label(__('Aktion'))
                    ->badge()
                    ->color('gray'),

                TextColumn::make('description')
                    ->label(__('Beschreibung'))
                    ->wrap(),
            ])
            ->emptyStateHeading(__('Noch keine Aktivität'));
    }
}
