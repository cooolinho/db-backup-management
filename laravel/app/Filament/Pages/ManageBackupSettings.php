<?php

namespace App\Filament\Pages;

use App\Settings\BackupSettings;
use App\Support\Audit;
use BackedEnum;
use Cron\CronExpression;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

class ManageBackupSettings extends SettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string $settings = BackupSettings::class;

    private const WEEKLY_CRON = '0 3 * * 0';

    /** @var array<string, mixed> */
    private array $before = [];

    public static function getNavigationLabel(): string
    {
        return __('Einstellungen');
    }

    public function getTitle(): string
    {
        return __('Backup-Einstellungen');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Zeitplan'))
                ->description(__('Wie oft automatisch ein Backup der Zieldatenbank erstellt wird.'))
                ->columns(2)
                ->schema([
                    Select::make('schedulePreset')
                        ->label(__('Intervall'))
                        ->live()
                        ->required()
                        ->options([
                            'off' => __('Deaktiviert'),
                            'hourly' => __('Stündlich'),
                            'every6hours' => __('Alle 6 Stunden'),
                            'daily' => __('Täglich'),
                            'weekly' => __('Wöchentlich (Sonntag)'),
                            'custom' => __('Benutzerdefiniert (Cron-Ausdruck)'),
                        ]),

                    TimePicker::make('dailyTime')
                        ->label(__('Uhrzeit'))
                        ->seconds(false)
                        ->default('03:00')
                        ->visible(fn (Get $get) => $get('schedulePreset') === 'daily'),

                    TextInput::make('schedule')
                        ->label(__('Cron-Ausdruck'))
                        ->helperText(__('Minute Stunde Tag Monat Wochentag, z. B. "0 3 * * *" für täglich um 3 Uhr.'))
                        ->visible(fn (Get $get) => $get('schedulePreset') === 'custom')
                        ->required(fn (Get $get) => $get('schedulePreset') === 'custom')
                        ->rule(function () {
                            return function (string $attribute, $value, $fail) {
                                if (filled($value) && ! CronExpression::isValidExpression($value)) {
                                    $fail(__('Kein gültiger Cron-Ausdruck.'));
                                }
                            };
                        }),
                ]),

            Section::make(__('Backup-Datei'))
                ->columns(2)
                ->schema([
                    Select::make('format')
                        ->label(__('Format'))
                        ->required()
                        ->options([
                            'sql' => 'SQL',
                            'sql.gz' => 'SQL (gzip)',
                            'zip' => 'ZIP',
                            'tar.gz' => 'TAR.GZ',
                        ]),

                    TextInput::make('keepLocal')
                        ->label(__('Lokal behalten'))
                        ->helperText(__('Anzahl der letzten Backups; -1 = unbegrenzt.'))
                        ->numeric()
                        ->required()
                        ->minValue(-1),

                    Toggle::make('s3Enabled')
                        ->label(__('Zusätzlich auf S3 sichern'))
                        ->live()
                        ->helperText(__('Zugangsdaten kommen aus der .env (BACKUP_S3_*).')),

                    TextInput::make('keepS3')
                        ->label(__('Auf S3 behalten'))
                        ->helperText(__('Anzahl der letzten Backups; -1 = unbegrenzt.'))
                        ->numeric()
                        ->required()
                        ->minValue(-1)
                        ->visible(fn (Get $get) => (bool) $get('s3Enabled')),
                ]),
        ]);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        [$preset, $dailyTime] = self::cronToPreset($data['schedule'] ?? null);

        $data['schedulePreset'] = $preset;
        $data['dailyTime'] = $dailyTime;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Captured here (not in afterSave()) because this runs before the
        // base SettingsPage::save() overwrites the real settings object.
        $current = app(BackupSettings::class);
        $this->before = [
            'schedule' => $current->schedule,
            'format' => $current->format,
            'keepLocal' => $current->keepLocal,
            'keepS3' => $current->keepS3,
            's3Enabled' => $current->s3Enabled,
        ];

        $data['schedule'] = match ($data['schedulePreset'] ?? 'custom') {
            'off' => null,
            'hourly' => '0 * * * *',
            'every6hours' => '0 */6 * * *',
            'daily' => self::dailyTimeToCron($data['dailyTime'] ?? '03:00'),
            'weekly' => self::WEEKLY_CRON,
            default => $data['schedule'] ?? null,
        };

        unset($data['schedulePreset'], $data['dailyTime']);

        return $data;
    }

    protected function afterSave(): void
    {
        $after = app(BackupSettings::class);
        $changes = [];

        foreach ($this->before as $key => $oldValue) {
            $newValue = $after->{$key};

            if ($oldValue !== $newValue) {
                // Flat "old → new" strings (not a nested array) so
                // KeyValueEntry on the audit log's view page - a flat
                // key/value display - can show it directly.
                $changes[$key] = self::displayValue($oldValue).' → '.self::displayValue($newValue);
            }
        }

        if ($changes !== []) {
            Audit::record('settings.updated', 'Backup-Einstellungen geändert', properties: $changes);
        }
    }

    /** @return array{0: string, 1: string} [preset, dailyTime] */
    private static function cronToPreset(?string $cron): array
    {
        return match (true) {
            blank($cron) => ['off', '03:00'],
            $cron === '0 * * * *' => ['hourly', '03:00'],
            $cron === '0 */6 * * *' => ['every6hours', '03:00'],
            $cron === self::WEEKLY_CRON => ['weekly', '03:00'],
            (bool) preg_match('/^(\d{1,2}) (\d{1,2}) \* \* \*$/', $cron, $m) => [
                'daily',
                sprintf('%02d:%02d', (int) $m[2], (int) $m[1]),
            ],
            default => ['custom', '03:00'],
        };
    }

    private static function dailyTimeToCron(string $time): string
    {
        $time = Carbon::parse($time);

        return "{$time->minute} {$time->hour} * * *";
    }

    private static function displayValue(mixed $value): string
    {
        return match (true) {
            $value === null => 'leer',
            is_bool($value) => $value ? 'ja' : 'nein',
            default => (string) $value,
        };
    }
}
