<?php

namespace App\Filament\Resources\Videos\Pages;

use App\Enums\VideoStatus;
use App\Filament\Resources\Videos\VideoResource;
use App\Jobs\GenerateVideoThumbnail;
use App\Jobs\ProcessVideo;
use App\Services\Video\VideoOptimization;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Gate;

class EditVideo extends EditRecord
{
    protected static string $resource = VideoResource::class;

    private bool $thumbnailTimeChanged = false;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->thumbnailTimeChanged = false;
        if (array_key_exists('thumbnail_offset_seconds', $data)) {
            $data['thumbnail_offset_seconds'] = filled($data['thumbnail_offset_seconds']) ? (float) $data['thumbnail_offset_seconds'] : null;
            $this->thumbnailTimeChanged = $data['thumbnail_offset_seconds'] !== $this->record->thumbnail_offset_seconds;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        if ($this->thumbnailTimeChanged) {
            $this->queueThumbnail();
        }
    }

    private function queueThumbnail(): void
    {
        Gate::authorize('update', $this->record);
        abort_unless(config('video.thumbnails') && $this->record->status === VideoStatus::Ready, 409, 'Preview generation is not available.');
        $this->record->update(['thumbnail_error' => null]);
        GenerateVideoThumbnail::dispatch($this->record->id)->afterCommit();
        Notification::make()->title('Thumbnail generation queued')->body('The video stays playable. Refresh this page after processing to see the new preview.')->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('optimize')->label(fn (): string => $this->record->optimization_status === 'failed' ? 'Retry MP4 optimization' : 'Create optimized MP4')->icon('heroicon-o-film')
                ->visible(fn (): bool => config('video.optimization_enabled') && ! $this->record->hasOptimizedCopy())
                ->disabled(fn (): bool => $this->record->status !== VideoStatus::Ready || in_array($this->record->optimization_status, ['queued', 'processing'], true))
                ->requiresConfirmation()->modalDescription('Create a private H.264/AAC MP4 up to 720p. The original stays intact and playable. Conversion may take several minutes.')
                ->action(function (): void {
                    Gate::authorize('update', $this->record);
                    app(VideoOptimization::class)->queue($this->record);
                    Notification::make()->title('MP4 optimization queued')->body('Refresh this page later to check its status.')->success()->send();
                }),
            Action::make('regenerateThumbnail')->label('Generate thumbnail')->icon('heroicon-o-photo')
                ->disabled(fn (): bool => ! config('video.thumbnails') || $this->record->status !== VideoStatus::Ready)
                ->action(function (): void {
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
                    if (! $this->thumbnailTimeChanged) {
                        $this->queueThumbnail();
                    }
                }),
            Action::make('reprocess')->label('Retry inspection')->visible(fn () => $this->record->status === VideoStatus::Failed)->requiresConfirmation()->action(function () {
                $this->record->update(['status' => VideoStatus::Processing, 'processing_error' => null]);
                ProcessVideo::dispatch($this->record->id);
                Notification::make()->title('Inspection queued')->success()->send();
            }),
            DeleteAction::make()->requiresConfirmation()->modalDescription('The original is retained until an explicit operator purge.'),
        ];
    }
}
