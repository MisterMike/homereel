<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
use App\Models\Video;
use App\Services\Video\MediaLock;
use App\Services\Video\VideoInspector;
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
                $video->fill($inspector->inspect($video));
                $previousThumbnail = $video->thumbnail_path;
                if (config('video.thumbnails')) {
                    try {
                        $video->thumbnail_path = $inspector->thumbnail($video);
                        $video->thumbnail_error = null;
                    } catch (\Throwable $e) {
                        $video->thumbnail_error = 'Preview generation failed. The video is still available; try generating another preview from its edit page.';
                        Log::warning('Thumbnail generation failed', ['video' => $video->id, 'exception' => $e]);
                    }
                }
                $video->status = VideoStatus::Ready;
                $video->save();
                if ($previousThumbnail && $previousThumbnail !== $video->thumbnail_path) {
                    try {
                        Storage::disk(config('video.disk'))->delete($previousThumbnail);
                    } catch (\Throwable $exception) {
                        Log::warning('Old preview cleanup failed', ['video' => $video->id, 'exception' => $exception]);
                    }
                }
            } catch (\Throwable $e) {
                $video->update(['status' => VideoStatus::Failed, 'processing_error' => 'Inspection failed. The original is retained. Check the private application log and retry processing.']);
                throw $e;
            }
        });
    }

    public function failed(?\Throwable $e): void
    {
        Video::whereKey($this->videoId)->where('status', '!=', VideoStatus::Ready)->update(['status' => VideoStatus::Failed, 'processing_error' => 'Processing exhausted its retries. The original is retained.']);
    }
}
