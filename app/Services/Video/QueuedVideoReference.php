<?php

namespace App\Services\Video;

use App\Jobs\DetectVideoRecordingDate;
use App\Jobs\GenerateVideoThumbnail;
use App\Jobs\OptimizeVideo;
use App\Jobs\PrepareWebVideo;
use App\Jobs\ProcessVideo;
use App\Notifications\VideoReady;
use Illuminate\Notifications\SendQueuedNotifications;

class QueuedVideoReference
{
    private const VIDEO_JOBS = [ProcessVideo::class, GenerateVideoThumbnail::class, DetectVideoRecordingDate::class, OptimizeVideo::class, PrepareWebVideo::class];

    public function fromJob(mixed $job): ?int
    {
        if (is_object($job) && in_array($job::class, self::VIDEO_JOBS, true)) {
            return $job->videoId;
        }
        if ($job instanceof SendQueuedNotifications && $job->notification instanceof VideoReady) {
            return $job->notification->video->id;
        }

        return null;
    }

    public function fromPayload(string $payload): ?int
    {
        $data = json_decode($payload, true, 32);
        if (! is_array($data)) {
            return null;
        }
        $class = $data['displayName'] ?? null;
        if (! in_array($class, [...self::VIDEO_JOBS, VideoReady::class], true)) {
            return null;
        }
        $id = $data['homereel_video_id'] ?? null;
        if (is_int($id) && $id > 0) {
            return $id;
        }
        $command = $data['data']['command'] ?? null;
        if (! in_array($class, self::VIDEO_JOBS, true) || ! is_string($command)
            || ! str_starts_with($command, 'O:'.strlen($class).':"'.$class.'":')) {
            return null;
        }
        if (! preg_match('/s:7:"videoId";i:([1-9][0-9]*);/', $command, $matches)) {
            return null;
        }

        return filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    }
}
