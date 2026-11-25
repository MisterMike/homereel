<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use App\Notifications\VideoReady;
use App\Services\Video\ConversionSettings;
use App\Services\Video\MediaLock;
use App\Services\Video\VideoOptimization;
use App\Services\Video\VideoOptimizer;
use App\Services\Video\VideoStorageBudget;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OptimizeVideo implements ShouldQueue
{
    use Queueable;

    protected bool $standard = false;

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
        if (! $video || $video->{$this->column('optimization_token')} !== $this->token || $this->hasCopy($video)) {
            return;
        }
        try {
            $lock->run(($this->standard ? 'web-optimization-' : 'optimization-').$video->uuid, function () use ($video, $optimizer, $lock): void {
                $eligible = $lock->run($video->uuid, function () use ($video, $lock): bool {
                    $video->refresh();
                    if ($video->{$this->column('optimization_token')} !== $this->token || $this->hasCopy($video)) {
                        return false;
                    }
                    if ($video->trashed() || $video->status !== VideoStatus::Ready || ! app(ConversionSettings::class)->enabled()) {
                        $video->update($this->attributes(['optimization_status' => 'failed', 'optimization_reserved_bytes' => 0, 'optimization_error' => 'Die Optimierung wurde abgebrochen. Das Original bleibt erhalten.']));

                        return false;
                    }
                    $lock->run('quota', function () use ($video): void {
                        $settings = app(ConversionSettings::class);
                        $profile = $this->standard ? ConversionSettings::WEB_PROFILE : $settings->profile();
                        $reservation = $settings->reservationBytes((float) $video->duration_seconds, $profile);
                        $used = app(VideoStorageBudget::class)->used() - $video->{$this->column('optimization_reserved_bytes')};
                        abort_if($used + $reservation > config('video.storage_limit_bytes'), 422, 'Nicht genug Videospeicher für die gewählte Konvertierungsqualität.');
                        $video->update($this->attributes(['optimization_status' => 'processing', 'optimization_profile' => $profile, 'optimization_reserved_bytes' => $reservation]));
                    }, wait: true);

                    return true;
                }, wait: true);
                if (! $eligible) {
                    return;
                }
                $metadata = $this->standard ? $optimizer->encode($video, $this->token, standard: true) : $optimizer->encode($video, $this->token);
                $lock->run($video->uuid, function () use ($video, $optimizer, $metadata, $lock): void {
                    $lock->run('quota', function () use ($video, $optimizer, $metadata): void {
                        $video->refresh();
                        $disk = Storage::disk(config('video.disk'));
                        if ($video->trashed() || $video->{$this->column('optimization_token')} !== $this->token || $video->status !== VideoStatus::Ready) {
                            $disk->delete($optimizer->temporaryPath($video, $this->token, $this->standard));
                            if ($video->{$this->column('optimization_token')} === $this->token) {
                                $video->update($this->attributes(['optimization_status' => 'failed', 'optimization_reserved_bytes' => 0]));
                            }

                            return;
                        }
                        $path = $optimizer->finalPath($video, $this->token, $this->standard);
                        if (! $disk->move($optimizer->temporaryPath($video, $this->token, $this->standard), $path)) {
                            throw new \RuntimeException('Cannot publish optimized MP4.');
                        }
                        DB::transaction(function () use ($video, $path, $metadata): void {
                            $video->update($this->attributes(['optimized_path' => $path, 'optimized_file_size' => $metadata['size'], 'optimized_width' => $metadata['width'], 'optimized_height' => $metadata['height'], 'optimization_status' => 'ready', 'optimization_reserved_bytes' => 0, 'optimization_error' => null]));
                            $uploader = User::find($video->uploaded_by);
                            if ($video->ready_notified_at === null && $uploader?->is_active) {
                                $uploader->notify(new VideoReady($video));
                                $video->update(['ready_notified_at' => now()]);
                            }
                        });
                    }, wait: true);
                }, wait: true);
            });
            if ($this->standard && $video->fresh()->hasWebCopy()) {
                $video->refresh();
                if (app(VideoOptimization::class)->needsHd($video) && ! in_array($video->optimization_status, ['queued', 'processing'], true)) {
                    try {
                        app(VideoOptimization::class)->queue($video);
                    } catch (\Throwable $exception) {
                        $video->update(['optimization_error' => 'HD konnte nicht eingeplant werden. Die Web-Version ist bereits abspielbar. Bitte HD erneut anfordern.']);
                        Log::warning('HD conversion could not be queued', ['video' => $video->id, 'exception' => $exception]);
                    }
                }
            }
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
        if (! $video || $video->{$this->column('optimization_token')} !== $this->token || $this->hasCopy($video)) {
            return;
        }
        $optimizer = app(VideoOptimizer::class);
        Storage::disk(config('video.disk'))->delete([$optimizer->temporaryPath($video, $this->token, $this->standard), $optimizer->finalPath($video, $this->token, $this->standard)]);
        $video->update($this->attributes(['optimization_status' => 'failed', 'optimization_reserved_bytes' => 0, 'optimization_error' => 'Die Konvertierung ist fehlgeschlagen. Das Original bleibt intern erhalten. Eine vorhandene Web-Version bleibt abspielbar. Bitte versuche es erneut.']));
        Log::warning('MP4 optimization failed', ['video' => $video->id, 'exception' => $exception]);
    }

    protected function column(string $column): string
    {
        if (! $this->standard) {
            return $column;
        }

        return str_replace(['optimization_', 'optimized_'], 'web_', $column);
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    protected function attributes(array $attributes): array
    {
        $result = [];
        foreach ($attributes as $column => $value) {
            $result[$this->column($column)] = $value;
        }

        return $result;
    }

    protected function hasCopy(Video $video): bool
    {
        return $this->standard ? $video->hasWebCopy() : $video->hasOptimizedCopy();
    }
}
