<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
use App\Models\Video;
use App\Services\Video\ConversionSettings;
use App\Services\Video\MediaLock;
use App\Services\Video\VideoOptimizer;
use App\Services\Video\VideoStorageBudget;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OptimizeVideo implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(public int $videoId, public string $token)
    {
        $this->timeout = config('video.optimization_timeout') + 90;
        $this->onConnection('transcoding')->onQueue('transcoding');
    }

    public function handle(VideoOptimizer $optimizer, MediaLock $lock): void
    {
        $video = Video::withTrashed()->find($this->videoId);
        if (! $video || $video->optimization_token !== $this->token || $video->hasOptimizedCopy()) {
            return;
        }
        try {
            $lock->run('optimization-'.$video->uuid, function () use ($video, $optimizer, $lock): void {
                $eligible = $lock->run($video->uuid, function () use ($video, $lock): bool {
                    $video->refresh();
                    if ($video->optimization_token !== $this->token || $video->hasOptimizedCopy()) {
                        return false;
                    }
                    if ($video->trashed() || $video->status !== VideoStatus::Ready || ! app(ConversionSettings::class)->enabled()) {
                        $video->update(['optimization_status' => 'failed', 'optimization_reserved_bytes' => 0, 'optimization_error' => 'Die Optimierung wurde abgebrochen. Das Original bleibt erhalten.']);

                        return false;
                    }
                    $lock->run('quota', function () use ($video): void {
                        $settings = app(ConversionSettings::class);
                        $profile = $settings->profile();
                        $reservation = $settings->reservationBytes((float) $video->duration_seconds, $profile);
                        $used = app(VideoStorageBudget::class)->used() - $video->optimization_reserved_bytes;
                        abort_if($used + $reservation > config('video.storage_limit_bytes'), 422, 'Nicht genug Videospeicher für die gewählte Konvertierungsqualität.');
                        $video->update(['optimization_status' => 'processing', 'optimization_profile' => $profile, 'optimization_reserved_bytes' => $reservation]);
                    }, wait: true);

                    return true;
                }, wait: true);
                if (! $eligible) {
                    return;
                }
                $metadata = $optimizer->encode($video, $this->token);
                $lock->run($video->uuid, function () use ($video, $optimizer, $metadata, $lock): void {
                    $lock->run('quota', function () use ($video, $optimizer, $metadata): void {
                        $video->refresh();
                        $disk = Storage::disk(config('video.disk'));
                        if ($video->trashed() || $video->optimization_token !== $this->token || $video->status !== VideoStatus::Ready) {
                            $disk->delete($optimizer->temporaryPath($video, $this->token));
                            if ($video->optimization_token === $this->token) {
                                $video->update(['optimization_status' => 'failed', 'optimization_reserved_bytes' => 0]);
                            }

                            return;
                        }
                        $path = $optimizer->finalPath($video, $this->token);
                        if (! $disk->move($optimizer->temporaryPath($video, $this->token), $path)) {
                            throw new \RuntimeException('Cannot publish optimized MP4.');
                        }
                        $video->update(['optimized_path' => $path, 'optimized_file_size' => $metadata['size'], 'optimized_width' => $metadata['width'], 'optimized_height' => $metadata['height'], 'optimization_status' => 'ready', 'optimization_reserved_bytes' => 0, 'optimization_error' => null]);
                    }, wait: true);
                }, wait: true);
            });
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() === 409) {
                return;
            }
            $this->failed($exception);
            throw $exception;
        } catch (\Throwable $exception) {
            $this->failed($exception);
            throw $exception;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $video = Video::withTrashed()->find($this->videoId);
        if (! $video || $video->optimization_token !== $this->token || $video->hasOptimizedCopy()) {
            return;
        }
        $optimizer = app(VideoOptimizer::class);
        Storage::disk(config('video.disk'))->delete([$optimizer->temporaryPath($video, $this->token), $optimizer->finalPath($video, $this->token)]);
        $video->update(['optimization_status' => 'failed', 'optimization_reserved_bytes' => 0, 'optimization_error' => 'Die MP4-Optimierung ist fehlgeschlagen. Das Original bleibt erhalten und verfügbar. Prüfe vor einem neuen Versuch das private Protokoll.']);
        Log::warning('MP4 optimization failed', ['video' => $video->id, 'exception' => $exception]);
    }
}
