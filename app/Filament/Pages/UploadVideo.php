<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class UploadVideo extends Page
{
    protected string $view = 'filament.pages.upload-video';

    protected static ?string $title = 'Upload video';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }
}
