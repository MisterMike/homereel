<?php

namespace App\Filament\Pages;

use App\Services\Video\ConversionSettings;
use BackedEnum;
use Filament\Forms\Components\Toggle;
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
                ->description('Erstelle eine separate optimierte Kopie für eine flüssigere Wiedergabe. Originale und vorhandene optimierte Kopien bleiben beim Deaktivieren erhalten.')
                ->schema([
                    Toggle::make('enabled')->label('MP4-Konvertierung aktivieren')->rules(['required', 'boolean'])->live()
                        ->helperText('Erfordert einen laufenden Konvertierungs-Worker. Aktiviere dies nur, wenn dein Hoster eine längere CPU-Nutzung durch FFmpeg erlaubt. Beim Deaktivieren werden laufende Konvertierungen abgeschlossen; wartende Aufträge werden bei der nächsten Prüfung abgebrochen.'),
                    Toggle::make('automatic')->label('Neue Uploads automatisch konvertieren')->rules(['required', 'boolean'])
                        ->disabled(fn (Get $get): bool => ! $get('enabled'))->dehydrated()
                        ->helperText('Nach der Prüfung jedes neuen Uploads wird eine optimierte MP4-Kopie eingeplant. Bestehende Videos können einzeln auf ihrer Bearbeitungsseite konvertiert werden.'),
                ]),
        ])->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $data = $this->settingsForm->getState();
        app(ConversionSettings::class)->save((bool) $data['enabled'], (bool) $data['automatic']);
        $this->settingsForm->fill(app(ConversionSettings::class)->values());
        Notification::make()->title('Videoeinstellungen gespeichert')->success()->send();
    }
}
