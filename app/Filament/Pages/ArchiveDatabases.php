<?php

namespace App\Filament\Pages;

use App\Backup\ArchiveNamer;
use App\Backup\DriverFactory;
use App\Filament\Pages\Actions\DeleteArchiveAction;
use App\Filament\Pages\Actions\SwapBackArchiveAction;
use App\Models\Restore;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Archive databases (created by a restore/swap - see ArchiveNamer) aren't
 * an Eloquent model, so this lists them straight from the server via
 * DatabaseDriver::listDatabases(), filtered through isArchiveOf() - the
 * same guard SwapArchiveJob/DropArchiveJob re-check server-side, so this
 * page can never show (or act on) anything else.
 */
class ArchiveDatabases extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $navigationLabel = 'Archiv-Datenbanken';

    protected static ?string $title = 'Archiv-Datenbanken';

    protected static ?int $navigationSort = 2;

    private bool $unreachable = false;

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        $database = config('database.connections.target.database');

        return $table
            ->records(fn () => $this->archives($database))
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable(),

                TextColumn::make('size')
                    ->label('Größe')
                    ->alignEnd(),

                TextColumn::make('created_at')
                    ->label('Erstellt'),

                TextColumn::make('version')
                    ->label('Version')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('restore')
                    ->label('Wiederherstellung')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                SwapBackArchiveAction::make($database),
                DeleteArchiveAction::make($database),
            ])
            ->emptyStateHeading(fn () => $this->unreachable ? 'Zieldatenbank nicht erreichbar' : 'Keine Archiv-Datenbanken')
            ->emptyStateDescription(fn () => $this->unreachable
                ? 'Die Verbindung zur Zieldatenbank ist gerade nicht möglich. Archiv-Datenbanken können erst wieder angezeigt werden, sobald sie erreichbar ist.'
                : 'Nach der ersten Wiederherstellung erscheint hier der vorherige Stand.');
    }

    /** @return Collection<int, array<string, mixed>> */
    private function archives(string $database): Collection
    {
        $driver = app(DriverFactory::class)->make();
        $namer = app(ArchiveNamer::class);

        try {
            $names = collect($driver->listDatabases($database))
                ->filter(fn (string $name) => $namer->isArchiveOf($database, $name))
                ->values();
        } catch (Throwable) {
            $this->unreachable = true;

            return collect();
        }

        $restoresByArchive = Restore::query()
            ->whereIn('archive_name', $names)
            ->latest('id')
            ->get()
            ->unique('archive_name')
            ->keyBy('archive_name');

        return $names->map(function (string $name) use ($driver, $namer, $database, $restoresByArchive) {
            $parsed = $namer->parse($database, $name);
            $restore = $restoresByArchive->get($name);

            return [
                '__key' => $name,
                'name' => $name,
                'size' => $this->humanSize($driver->databaseSize($name)),
                'created_at' => $parsed !== null
                    ? $parsed['timestamp']->format('d.m.Y H:i')
                    : '—',
                'version' => $parsed['version'] ?? '—',
                'restore' => $restore
                    ? match ($restore->status) {
                        'success' => 'Erfolgreich',
                        'failed' => 'Fehlgeschlagen',
                        default => 'Läuft',
                    }
                    : '—',
            ];
        });
    }

    private function humanSize(?int $bytes): string
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
}
