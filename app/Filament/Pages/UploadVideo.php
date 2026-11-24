<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class UploadVideo extends Page
{
    protected string $view = 'filament.pages.upload-video';

    protected static ?string $title = 'Video hochladen';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canUploadVideos();
    }
}
