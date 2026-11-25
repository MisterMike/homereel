<?php

namespace App\Services\Video;

use App\Models\Video;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class VideoOptimizer
{
    public function temporaryPath(Video $video, string $token, bool $standard = false): string
    {
        return ($standard ? 'web/' : 'optimized/').$video->uuid.'-'.$token.'.part.mp4';
    }

    public function finalPath(Video $video, string $token, bool $standard = false): string
    {
        return ($standard ? 'web/' : 'optimized/').$video->uuid.'-'.$token.'.mp4';
    }

    /** @return array{size: int, width: int, height: int} */
    public function encode(Video $video, string $token, bool $standard = false): array
    {
        $disk = Storage::disk(config('video.disk'));
        $disk->makeDirectory($standard ? 'web' : 'optimized');
        $path = $this->temporaryPath($video, $token, $standard);
        $settings = app(ConversionSettings::class);
        $profile = $standard ? ($video->web_profile ?? ConversionSettings::WEB_PROFILE) : ($video->optimization_profile ?? $settings->profile());
        $reservation = $standard ? $video->web_reserved_bytes : $video->optimization_reserved_bytes;
        $height = $profile['max_height'];
        $width = (int) floor($height * 16 / 9 / 2) * 2;
        $bitrate = $video->duration_seconds > 0 ? max((int) $video->source_bitrate, (int) ceil($video->file_size * 8 / $video->duration_seconds)) : 0;
        $copy = $video->browser_compatible && $bitrate > 0 && $bitrate <= $profile['video_bitrate'] * 1000
            && ($height === 0 || ($video->width <= $width && $video->height <= $height));
        $arguments = $copy ? ['-c', 'copy'] : $settings->ffmpegArguments($profile);
        $process = new Process([
            config('video.ffmpeg'), '-nostdin', '-v', 'error', '-threads', '1', '-protocol_whitelist', 'file',
            '-i', $disk->path($video->storage_path), '-map', '0:v:0', '-map', '0:a:0?', '-map_metadata', '-1', '-map_chapters', '-1',
            ...$arguments, '-threads', '1', '-filter_threads', '1',
            '-movflags', '+faststart', '-fs', (string) $reservation, '-y', $disk->path($path),
        ]);
        $errorTail = '';
        $process->setTimeout(config('video.optimization_timeout'))->disableOutput()->run(function (string $type, string $data) use (&$errorTail): void {
            if ($type === Process::ERR) {
                $errorTail = substr($errorTail.$data, -16384);
            }
        });
        if (! $process->isSuccessful()) {
            throw new \RuntimeException('FFmpeg optimization failed: '.$errorTail);
        }
        $probe = new Video(['storage_path' => $path, 'mime_type' => 'video/mp4']);
        $metadata = app(VideoInspector::class)->inspect($probe);
        $size = $disk->size($path);
        if (! $metadata['browser_compatible'] || $size <= 0 || $size > $reservation
            || abs($metadata['duration_seconds'] - $video->duration_seconds) > max(1, $video->duration_seconds * 0.01)
            || ! chmod($disk->path($path), 0600)) {
            throw new \RuntimeException('The optimized MP4 did not pass verification.');
        }

        return ['size' => $size, 'width' => $metadata['width'], 'height' => $metadata['height']];
    }
}
