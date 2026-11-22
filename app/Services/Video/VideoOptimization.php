<?php

namespace App\Services\Video;

use App\Enums\VideoStatus;
use App\Jobs\OptimizeVideo;
use App\Models\Video;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VideoOptimization
{
    public function queue(Video $video): void
    {
        abort_unless(config('video.optimization_enabled'), 409, 'MP4 optimization is disabled on this server.');
        app(MediaLock::class)->run($video->uuid, function () use ($video): void {
            app(MediaLock::class)->run('quota', function () use ($video): void {
                DB::transaction(function () use ($video): void {
                    $video->refresh();
                    abort_unless(! $video->trashed() && $video->status === VideoStatus::Ready && $video->storage_provider === 'local', 409, 'The original must be ready.');
                    abort_if($video->optimized_path || in_array($video->optimization_status, ['queued', 'processing'], true), 409, 'An optimized copy already exists or is being prepared.');
                    $duration = (float) $video->duration_seconds;
                    abort_unless(is_finite($duration) && $duration > 0, 409, 'Inspect the video before optimizing it.');
                    $reservation = (int) ceil($duration * 2628000 / 8 * 1.15) + 1048576;
                    abort_if(app(VideoStorageBudget::class)->used() + $reservation > config('video.storage_limit_bytes'), 422, 'Not enough video storage for an optimized copy.');
                    $token = (string) Str::uuid();
                    $video->update(['optimization_status' => 'queued', 'optimization_token' => $token, 'optimization_reserved_bytes' => $reservation, 'optimization_error' => null]);
                    OptimizeVideo::dispatch($video->id, $token)->beforeCommit();
                });
            });
        });
    }
}
