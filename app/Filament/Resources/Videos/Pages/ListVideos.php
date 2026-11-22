<?php

namespace App\Filament\Resources\Videos\Pages;

use App\Filament\Pages\UploadVideo;
use App\Filament\Resources\Videos\VideoResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListVideos extends ListRecords
{
    protected static string $resource = VideoResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('upload')->label('Upload video')->url(UploadVideo::getUrl())];
    }
}
