<?php

namespace App\Filament\Resources\Videos\Pages;

use App\Enums\VideoStatus;
use App\Filament\Resources\Videos\VideoResource;
use App\Jobs\ProcessVideo;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditVideo extends EditRecord
{
    protected static string $resource = VideoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reprocess')->label('Retry inspection')->visible(fn () => $this->record->status === VideoStatus::Failed)->requiresConfirmation()->action(function () {
                $this->record->update(['status' => VideoStatus::Processing, 'processing_error' => null]);
                ProcessVideo::dispatch($this->record->id);
                Notification::make()->title('Inspection queued')->success()->send();
            }),
            DeleteAction::make()->requiresConfirmation()->modalDescription('The original is retained until an explicit operator purge.'),
        ];
    }
}
