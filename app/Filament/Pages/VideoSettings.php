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

    protected static ?string $title = 'Video settings';

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
            Section::make('MP4 conversion')
                ->description('Create a separate optimized copy for smoother playback. Originals and existing optimized copies are kept when conversion is disabled.')
                ->schema([
                    Toggle::make('enabled')->label('Enable MP4 conversion')->rules(['required', 'boolean'])->live()
                        ->helperText('Requires a running conversion worker. Enable only where your host permits sustained FFmpeg CPU usage. Turning this off lets active conversions finish; queued conversions are cancelled when the worker checks them.'),
                    Toggle::make('automatic')->label('Automatically convert new uploads')->rules(['required', 'boolean'])
                        ->disabled(fn (Get $get): bool => ! $get('enabled'))->dehydrated()
                        ->helperText('Queue an optimized MP4 after each new upload is inspected. Existing videos can be converted individually from their edit page.'),
                ]),
        ])->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $data = $this->settingsForm->getState();
        app(ConversionSettings::class)->save((bool) $data['enabled'], (bool) $data['automatic']);
        $this->settingsForm->fill(app(ConversionSettings::class)->values());
        Notification::make()->title('Video settings saved')->success()->send();
    }
}
