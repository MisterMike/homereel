<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
use App\Models\Video;
use App\Services\Video\ConversionSettings;
use App\Services\Video\MediaLock;
use App\Services\Video\VideoInspector;
use App\Services\Video\VideoOptimization;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessVideo implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout;

    public function __construct(public int $videoId)
    {
        $this->timeout = 2 * config('video.process_timeout') + 30;
    }

    public function backoff(): array
    {
        return [60, 180];
    }

    public function handle(VideoInspector $inspector, MediaLock $lock): void
    {
        $video = Video::find($this->videoId);
        if (! $video || $video->status === VideoStatus::Ready) {
            return;
        }
        $lock->run($video->uuid, function () use ($video, $inspector) {
            $video->refresh();
            if ($video->trashed()) {
                return;
            }
            $video->update(['status' => VideoStatus::Processing, 'processing_error' => null]);
            try {
                $metadata = $inspector->inspect($video);
                $recordedOn = $metadata['recorded_on'] ?? null;
                unset($metadata['recorded_on']);
                $video->fill($metadata);
                $previousThumbnail = $video->thumbnail_path;
                if (config('video.thumbnails')) {
                    try {
                        $video->thumbnail_path = $inspector->thumbnail($video);
                        $video->thumbnail_error = null;
                    } catch (\Throwable $e) {
                        $video->thumbnail_error = 'Das Vorschaubild konnte nicht erstellt werden. Das Video bleibt verfügbar. Versuche es auf der Bearbeitungsseite erneut.';
                        Log::warning('Thumbnail generation failed', ['video' => $video->id, 'exception' => $e]);
                    }
                }
                $video->status = VideoStatus::Ready;
                $video->save();
                if ($recordedOn) {
                    Video::whereKey($video->id)->whereNull('recorded_on')->update(['recorded_on' => $recordedOn]);
                }
                if ($previousThumbnail && $previousThumbnail !== $video->thumbnail_path) {
                    try {
                        Storage::disk(config('video.disk'))->delete($previousThumbnail);
                    } catch (\Throwable $exception) {
                        Log::warning('Old preview cleanup failed', ['video' => $video->id, 'exception' => $exception]);
                    }
                }
            } catch (\Throwable $e) {
                $video->update(['status' => VideoStatus::Failed, 'processing_error' => 'Die Prüfung ist fehlgeschlagen. Das Original bleibt erhalten. Prüfe das private Anwendungsprotokoll und starte die Verarbeitung erneut.']);
                throw $e;
            }
        });
        if (app(ConversionSettings::class)->automatic()) {
            try {
                app(VideoOptimization::class)->queue($video, standard: true);
            } catch (\Throwable $exception) {
                Video::whereKey($video->id)->where('web_status', 'not_requested')->update(['web_status' => 'failed', 'web_error' => 'Die Web-Version konnte nicht eingeplant werden. Bitte prüfe den verfügbaren Speicher und fordere die Web-Version auf der Bearbeitungsseite erneut an.']);
                Log::warning('Automatic optimization could not be queued', ['video' => $video->id, 'exception' => $exception]);
            }
        }
    }

    public function failed(?\Throwable $e): void
    {
        Video::whereKey($this->videoId)->where('status', '!=', VideoStatus::Ready)->update(['status' => VideoStatus::Failed, 'processing_error' => 'Die Verarbeitung ist nach mehreren Versuchen fehlgeschlagen. Das Original bleibt erhalten.']);
    }
}
