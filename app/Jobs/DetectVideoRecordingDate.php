<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
use App\Models\Video;
use App\Services\Video\MediaLock;
use App\Services\Video\VideoInspector;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DetectVideoRecordingDate implements ShouldQueue
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
        $video = Video::find($this->videoId);
        if (! $video || $video->recorded_on || $video->status !== VideoStatus::Ready) {
            return;
        }

        $lock->run($video->uuid, function () use ($video, $inspector): void {
            $video->refresh();
            if ($video->trashed() || $video->recorded_on || $video->status !== VideoStatus::Ready) {
                return;
            }
            $recordedOn = $inspector->inspect($video)['recorded_on'] ?? null;
            if ($recordedOn) {
                Video::whereKey($video->id)->whereNull('recorded_on')->update(['recorded_on' => $recordedOn]);
            }
        });
    }
}
