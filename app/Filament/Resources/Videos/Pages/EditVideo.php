<?php

namespace App\Filament\Resources\Videos\Pages;

use App\Enums\VideoStatus;
use App\Filament\Resources\Videos\VideoResource;
use App\Jobs\GenerateVideoThumbnail;
use App\Jobs\ProcessVideo;
use App\Services\Video\ConversionSettings;
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
        abort_unless(config('video.thumbnails') && $this->record->status === VideoStatus::Ready, 409, 'Die Erstellung von Vorschaubildern ist nicht verfügbar.');
        $this->record->update(['thumbnail_error' => null]);
        GenerateVideoThumbnail::dispatch($this->record->id)->afterCommit();
        Notification::make()->title('Vorschaubilderstellung vorgemerkt')->body('Das Video bleibt abspielbar. Lade diese Seite nach der Verarbeitung neu, um das neue Vorschaubild zu sehen.')->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->getSaveFormAction()->formId('form'),
            Action::make('optimize')->label(fn (): string => $this->record->optimization_status === 'failed' ? 'MP4-Optimierung erneut versuchen' : 'Optimierte MP4 erstellen')->icon('heroicon-o-film')
                ->color('gray')
                ->visible(fn (): bool => app(ConversionSettings::class)->enabled() && ! $this->record->hasOptimizedCopy())
                ->disabled(fn (): bool => $this->record->status !== VideoStatus::Ready || in_array($this->record->optimization_status, ['queued', 'processing'], true))
                ->requiresConfirmation()->modalDescription('Erstelle eine private H.264/AAC-MP4-Datei mit bis zu 720p. Das Original bleibt erhalten und abspielbar. Die Konvertierung kann mehrere Minuten dauern.')
                ->action(function (): void {
                    Gate::authorize('update', $this->record);
                    app(VideoOptimization::class)->queue($this->record);
                    Notification::make()->title('MP4-Optimierung vorgemerkt')->body('Lade diese Seite später neu, um den Status zu prüfen.')->success()->send();
                }),
            Action::make('regenerateThumbnail')->label('Vorschaubild erstellen')->icon('heroicon-o-photo')
                ->color('gray')
                ->disabled(fn (): bool => ! config('video.thumbnails') || $this->record->status !== VideoStatus::Ready)
                ->action(function (): void {
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
                    if (! $this->thumbnailTimeChanged) {
                        $this->queueThumbnail();
                    }
                }),
            Action::make('reprocess')->label('Prüfung erneut versuchen')->color('gray')->visible(fn () => $this->record->status === VideoStatus::Failed)->requiresConfirmation()->action(function () {
                $this->record->update(['status' => VideoStatus::Processing, 'processing_error' => null]);
                ProcessVideo::dispatch($this->record->id);
                Notification::make()->title('Prüfung vorgemerkt')->success()->send();
            }),
        ];
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCancelFormAction(),
            DeleteAction::make()->outlined()->icon('heroicon-o-trash')->requiresConfirmation()
                ->modalDescription('Das Original bleibt bis zur ausdrücklichen Bereinigung auf dem Server erhalten.'),
        ];
    }
}
