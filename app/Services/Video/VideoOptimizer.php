<?php

namespace App\Services\Video;

use App\Models\Video;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class VideoOptimizer
{
    public function temporaryPath(Video $video, string $token): string
    {
        return 'optimized/'.$video->uuid.'-'.$token.'.part.mp4';
    }

    public function finalPath(Video $video, string $token): string
    {
        return 'optimized/'.$video->uuid.'-'.$token.'.mp4';
    }

    /** @return array{size: int, width: int, height: int} */
    public function encode(Video $video, string $token): array
    {
        $disk = Storage::disk(config('video.disk'));
        $disk->makeDirectory('optimized');
        $path = $this->temporaryPath($video, $token);
        $process = new Process([
            config('video.ffmpeg'), '-nostdin', '-v', 'error', '-threads', '1', '-protocol_whitelist', 'file',
            '-i', $disk->path($video->storage_path), '-map', '0:v:0', '-map', '0:a:0?', '-map_metadata', '-1', '-map_chapters', '-1',
            '-vf', 'scale=w=min(1280\\,iw):h=min(720\\,ih):force_original_aspect_ratio=decrease:force_divisible_by=2,setsar=1',
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23', '-maxrate', '2500k', '-bufsize', '5000k', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', '128k', '-ac', '2', '-threads', '1', '-filter_threads', '1',
            '-movflags', '+faststart', '-fs', (string) $video->optimization_reserved_bytes, '-y', $disk->path($path),
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
        if (! $metadata['browser_compatible'] || $size <= 0 || $size > $video->optimization_reserved_bytes
            || abs($metadata['duration_seconds'] - $video->duration_seconds) > max(1, $video->duration_seconds * 0.01)
            || ! chmod($disk->path($path), 0600)) {
            throw new \RuntimeException('The optimized MP4 did not pass verification.');
        }

        return ['size' => $size, 'width' => $metadata['width'], 'height' => $metadata['height']];
    }
}
