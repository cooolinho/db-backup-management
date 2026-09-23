<?php

namespace App\Filament\Resources\AuditLogs\Tables;

use App\Models\AuditLog;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AuditLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('Zeitpunkt'))
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),

                TextColumn::make('user.name')
                    ->label(__('Benutzer'))
                    ->default(__('System')),

                TextColumn::make('action')
                    ->label(__('Aktion'))
                    ->badge()
                    ->color('gray'),

                TextColumn::make('description')
                    ->label(__('Beschreibung'))
                    ->wrap()
                    ->searchable(),

                TextColumn::make('ip')
                    ->label(__('IP'))
                    ->default('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('action')
                    ->label(__('Aktion'))
                    ->options(fn () => AuditLog::query()->distinct()->orderBy('action')->pluck('action', 'action')->all()),

                SelectFilter::make('user_id')
                    ->label(__('Benutzer'))
                    ->relationship('user', 'name'),

                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label(__('Von')),
                        DatePicker::make('until')->label(__('Bis')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading(__('Noch keine Einträge'));
    }
}
