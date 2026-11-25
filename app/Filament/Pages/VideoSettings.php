<?php

namespace App\Filament\Pages;

use App\Services\Video\ConversionSettings;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class VideoSettings extends Page
{
    protected string $view = 'filament.pages.video-settings';

    protected static ?string $title = 'Videoeinstellungen';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?int $navigationSort = 90;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->settingsForm->fill(app(ConversionSettings::class)->values());
    }

    public function settingsForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('MP4-Konvertierung')
                ->description('Neue Videos werden zuerst in Standardqualität bereitgestellt (bis 720p, maximal 2500 kbit/s, CRF 23). Danach folgt bei Bedarf HD. Das Original bleibt intern und wird nicht im Player angeboten.')
                ->schema([
                    Toggle::make('enabled')->label('MP4-Konvertierung aktivieren')->rules(['required', 'boolean'])->live()
                        ->helperText('Erfordert einen laufenden Konvertierungs-Worker. Aktiviere dies nur, wenn dein Hoster eine längere CPU-Nutzung durch FFmpeg erlaubt. Beim Deaktivieren werden laufende Konvertierungen abgeschlossen; wartende Aufträge werden bei der nächsten Prüfung abgebrochen.'),
                    Toggle::make('automatic')->label('Neue Uploads automatisch konvertieren')->rules(['required', 'boolean'])
                        ->disabled(fn (Get $get): bool => ! $get('enabled'))->dehydrated()
                        ->helperText('Nach jedem Upload wird zuerst die Standardversion eingeplant. Sobald sie bereit ist, erhält die hochladende Person eine E-Mail. Bestehende Videos können auf ihrer Bearbeitungsseite mit «Web-Version erstellen» vorbereitet werden.'),
                ]),
            Section::make('Höhere Web-Qualität (HD)')
                ->description('Diese Einstellungen gelten für HD. HD wird nur erstellt, wenn die resultierende Auflösung höher als die der Standardversion ist. Mit höchstens 720p entfällt HD normalerweise. Änderungen gelten für neue und wartende HD-Aufträge; laufende Aufträge behalten ihre Qualität.')
                ->columns(2)
                ->schema([
                    TextInput::make('crf')->label('Bildqualität (CRF)')->numeric()->required()->integer()->minValue(16)->maxValue(30)
                        ->helperText('Kleinerer Wert = mehr Details und meist grössere Dateien. 18 für hohe Qualität, 23 war die bisherige Einstellung.'),
                    Select::make('max_height')->label('Maximale Auflösung')->required()->options([
                        0 => 'Originalgrösse beibehalten', 480 => '480p', 720 => '720p', 1080 => '1080p (Full HD)', 1440 => '1440p', 2160 => '2160p (4K)',
                    ])->helperText('Wähle 1080p oder mehr für eine zusätzliche HD-Version. Kleinere Videos werden nicht hochskaliert. Die Begrenzung gilt für ein 16:9-Bildfeld.'),
                    TextInput::make('video_bitrate')->label('Maximale Videobitrate')->suffix('kbit/s')->numeric()->required()->integer()->minValue(250)->maxValue(100000)
                        ->helperText('Eine zu niedrige Obergrenze begrenzt die Qualität auch bei niedrigem CRF. Bisher: 2500 kbit/s.'),
                    Select::make('audio_bitrate')->label('Audiobitrate')->required()->options([
                        64 => '64 kbit/s', 96 => '96 kbit/s', 128 => '128 kbit/s', 192 => '192 kbit/s', 256 => '256 kbit/s', 320 => '320 kbit/s',
                    ]),
                    Select::make('preset')->label('Encoding-Preset')->required()->options(array_combine(ConversionSettings::PRESETS, ConversionSettings::PRESETS))
                        ->helperText('Langsamere Presets können bei ähnlicher Qualität kleinere Dateien erzeugen, benötigen aber mehr Rechenzeit. Reihenfolge: ultrafast bis veryslow.'),
                    TextEntry::make('ffmpeg_options')->label('Gespeicherte FFmpeg-Optionen')->columnSpanFull()->copyable()->wrap()
                        ->state(fn (): string => implode(' ', app(ConversionSettings::class)->ffmpegArguments(app(ConversionSettings::class)->profile()))),
                ]),
        ])->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $data = $this->settingsForm->getState();
        app(ConversionSettings::class)->save((bool) $data['enabled'], (bool) $data['automatic'], array_intersect_key($data, ConversionSettings::DEFAULT_PROFILE));
        $this->settingsForm->fill(app(ConversionSettings::class)->values());
        Notification::make()->title('Videoeinstellungen gespeichert')->success()->send();
    }
}
