<?php

namespace App\Filament\Widgets;

use App\Backup\ArchiveNamer;
use App\Backup\BackupHealth;
use App\Backup\DriverFactory;
use App\Settings\BackupSettings;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Throwable;

class BackupStatusOverview extends BaseWidget
{
    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        return [
            $this->targetDatabaseStat(),
            $this->lastBackupStat(),
            $this->nextRunStat(),
            $this->storageStat(),
            $this->archivesStat(),
        ];
    }

    private function targetDatabaseStat(): Stat
    {
        $database = config('database.connections.target.database');

        try {
            $version = app(DriverFactory::class)->make()->serverVersion();

            return Stat::make(__('Zieldatenbank'), $database)
                ->description($version)
                ->descriptionIcon(Heroicon::CircleStack)
                ->color('success');
        } catch (Throwable) {
            return Stat::make(__('Zieldatenbank'), $database)
                ->description(__('Nicht erreichbar'))
                ->descriptionIcon(Heroicon::ExclamationTriangle)
                ->color('danger');
        }
    }

    private function lastBackupStat(): Stat
    {
        $health = app(BackupHealth::class);
        $last = $health->lastSuccessfulBackup();

        if ($last === null) {
            return Stat::make(__('Letztes Backup'), __('Noch keins'))
                ->descriptionIcon(Heroicon::Clock)
                ->color('gray');
        }

        return Stat::make(__('Letztes Backup'), $last->finished_at->diffForHumans())
            ->description($last->finished_at->format('d.m.Y H:i'))
            ->descriptionIcon(Heroicon::CheckCircle)
            ->color($health->isStale() ? 'danger' : 'success');
    }

    private function nextRunStat(): Stat
    {
        $health = app(BackupHealth::class);
        $next = $health->nextRunAt();

        if ($next === null) {
            return Stat::make(__('Nächster Lauf'), __('Deaktiviert'))
                ->descriptionIcon(Heroicon::PauseCircle)
                ->color('gray');
        }

        return Stat::make(__('Nächster Lauf'), $next->diffForHumans())
            ->description($next->format('d.m.Y H:i'))
            ->descriptionIcon(Heroicon::Clock)
            ->color('info');
    }

    private function storageStat(): Stat
    {
        $path = config('backup.path', '/backups');

        $free = @disk_free_space($path);
        $total = @disk_total_space($path);

        if ($free === false || $total === false) {
            return Stat::make(__('Speicher'), __('Unbekannt'))->color('gray');
        }

        $used = $total - $free;
        $percentUsed = $total > 0 ? round(($used / $total) * 100) : 0;

        return Stat::make(__('Speicher'), __(':size belegt', ['size' => $this->humanSize($used)]))
            ->description(__(':size frei (:percent %)', ['size' => $this->humanSize($free), 'percent' => $percentUsed]))
            ->descriptionIcon(Heroicon::CircleStack)
            ->color($percentUsed >= 90 ? 'danger' : ($percentUsed >= 75 ? 'warning' : 'success'));
    }

    private function archivesStat(): Stat
    {
        $database = config('database.connections.target.database');

        try {
            $driver = app(DriverFactory::class)->make();
            $namer = app(ArchiveNamer::class);

            $count = collect($driver->listDatabases($database))
                ->filter(fn (string $name) => $namer->isArchiveOf($database, $name))
                ->count();

            return Stat::make(__('Archiv-Datenbanken'), (string) $count)
                ->descriptionIcon(Heroicon::ArchiveBox)
                ->color('gray');
        } catch (Throwable) {
            return Stat::make(__('Archiv-Datenbanken'), '—')
                ->description(__('Nicht erreichbar'))
                ->color('gray');
        }
    }

    private function humanSize(float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 1).' '.$units[$i];
    }
}
