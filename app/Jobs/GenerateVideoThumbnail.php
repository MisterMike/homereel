<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
use App\Models\Video;
use App\Services\Video\MediaLock;
use App\Services\Video\VideoInspector;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GenerateVideoThumbnail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout;

    public function __construct(public int $videoId)
    {
        $this->timeout = config('video.process_timeout') + 30;
    }

    public function backoff(): array
    {
        return [60, 180];
    }

    public function handle(VideoInspector $inspector, MediaLock $lock): void
    {
        if (! config('video.thumbnails')) {
            return;
        }
        $video = Video::find($this->videoId);
        if (! $video || $video->status !== VideoStatus::Ready) {
            return;
        }
        $lock->run($video->uuid, function () use ($video, $inspector) {
            $video->refresh();
            if ($video->trashed() || $video->status !== VideoStatus::Ready) {
                return;
            }
            $disk = Storage::disk(config('video.disk'));
            $newPath = null;
            $published = false;
            $oldPath = null;
            try {
                $newPath = $inspector->thumbnail($video);
                $published = DB::transaction(function () use ($video, $newPath, &$oldPath): bool {
                    $current = Video::whereKey($video->id)->lockForUpdate()->first();
                    // A newer selection or deletion must win over an in-flight generation.
                    if (! $current || $current->status !== VideoStatus::Ready || $current->thumbnail_offset_seconds !== $video->thumbnail_offset_seconds) {
                        return false;
                    }
                    $oldPath = $current->thumbnail_path;
                    $current->update(['thumbnail_path' => $newPath, 'thumbnail_error' => null]);

                    return true;
                });
                if (! $published) {
                    $disk->delete($newPath);

                    return;
                }
            } catch (\Throwable $exception) {
                if ($newPath && ! $published) {
                    $disk->delete($newPath);
                }
                Video::whereKey($video->id)->where('thumbnail_offset_seconds', $video->thumbnail_offset_seconds)->update(['thumbnail_error' => 'Das Vorschaubild konnte nicht erstellt werden. Das bisherige Vorschaubild und das Original bleiben erhalten. Wähle einen anderen Zeitpunkt oder versuche es erneut.']);
                throw $exception;
            }
            if ($oldPath && $oldPath !== $newPath) {
                try {
                    $disk->delete($oldPath);
                } catch (\Throwable $exception) {
                    Log::warning('Old preview cleanup failed', ['video' => $video->id, 'exception' => $exception]);
                }
            }
        });
    }

    public function failed(?\Throwable $exception): void
    {
        Video::whereKey($this->videoId)->update(['thumbnail_error' => 'Das Vorschaubild konnte auch nach mehreren Versuchen nicht erstellt werden. Das bisherige Vorschaubild und das Original bleiben erhalten. Versuche es erneut.']);
    }
}
