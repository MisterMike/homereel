<?php

namespace App\Services\Video;

use App\Enums\VideoStatus;
use App\Jobs\OptimizeVideo;
use App\Jobs\PrepareWebVideo;
use App\Models\Video;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VideoOptimization
{
    public function needsHd(Video $video): bool
    {
        if (! $video->hasWebCopy() || $video->hasOptimizedCopy() || $video->width <= 0 || $video->height <= 0) {
            return false;
        }
        $profile = app(ConversionSettings::class)->profile();
        $height = $profile['max_height'];
        $scale = $height === 0 ? 1 : min(1, $height / $video->height, floor($height * 16 / 9 / 2) * 2 / $video->width);

        return ($video->width * $scale) >= $video->web_width + 2 || ($video->height * $scale) >= $video->web_height + 2;
    }

    public function queue(Video $video, bool $standard = false): void
    {
        abort_unless(app(ConversionSettings::class)->enabled(), 409, 'MP4 optimization is disabled on this server.');
        app(MediaLock::class)->run($video->uuid, function () use ($video, $standard): void {
            app(MediaLock::class)->run('quota', function () use ($video, $standard): void {
                DB::transaction(function () use ($video, $standard): void {
                    $video->refresh();
                    abort_unless(! $video->trashed() && $video->status === VideoStatus::Ready && $video->storage_provider === 'local', 409, 'Das Original muss bereit sein.');
                    abort_if(($standard ? $video->web_path : $video->optimized_path) || in_array($standard ? $video->web_status : $video->optimization_status, ['queued', 'processing'], true), 409, 'Eine optimierte Kopie ist bereits vorhanden oder wird vorbereitet.');
                    $duration = (float) $video->duration_seconds;
                    abort_unless(is_finite($duration) && $duration > 0, 409, 'Prüfe das Video vor der Optimierung.');
                    $settings = app(ConversionSettings::class);
                    $profile = $standard ? ConversionSettings::WEB_PROFILE : $settings->profile();
                    $reservation = $settings->reservationBytes($duration, $profile);
                    abort_if(app(VideoStorageBudget::class)->used() + $reservation > config('video.storage_limit_bytes'), 422, 'Nicht genug Videospeicher für eine optimierte Kopie.');
                    $token = (string) Str::uuid();
                    $video->update($standard
                        ? ['web_status' => 'queued', 'web_token' => $token, 'web_reserved_bytes' => $reservation, 'web_profile' => $profile, 'web_error' => null]
                        : ['optimization_status' => 'queued', 'optimization_token' => $token, 'optimization_reserved_bytes' => $reservation, 'optimization_profile' => $profile, 'optimization_error' => null]);
                    if ($standard) {
                        PrepareWebVideo::dispatch($video->id, $token)->beforeCommit();
                    } else {
                        OptimizeVideo::dispatch($video->id, $token)->beforeCommit();
                    }
                });
            });
        });
    }
}
