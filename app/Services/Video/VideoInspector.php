<?php

namespace App\Services\Video;

use App\Models\Video;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class VideoInspector
{
    public function inspect(Video $video): array
    {
        $path = Storage::disk(config('video.disk'))->path($video->storage_path);
        // Disable external protocols: an uploaded playlist must never fetch network resources.
        $probe = new Process([config('video.ffprobe'), '-v', 'error', '-protocol_whitelist', 'file', '-show_entries', 'format=duration,format_name:stream=codec_type,codec_name,width,height,pix_fmt', '-of', 'json', $path]);
        $probe->setTimeout(config('video.process_timeout'))->mustRun();
        $data = json_decode($probe->getOutput(), true, 32, JSON_THROW_ON_ERROR);
        $streams = collect($data['streams'] ?? []);
        $visual = $streams->firstWhere('codec_type', 'video');
        $audio = $streams->firstWhere('codec_type', 'audio');
        if (! $visual || empty($visual['width']) || empty($visual['height'])) {
            throw new \RuntimeException('No video stream found.');
        }
        $format = $data['format']['format_name'] ?? '';
        $duration = (float) ($data['format']['duration'] ?? 0);
        if (! is_finite($duration) || $duration <= 0) {
            throw new \RuntimeException('Invalid video duration.');
        }

        return [
            'duration_seconds' => $duration, 'width' => $visual['width'], 'height' => $visual['height'],
            'video_codec' => $visual['codec_name'] ?? null, 'audio_codec' => $audio['codec_name'] ?? null,
            'container_format' => $format,
            'browser_compatible' => $video->mime_type === 'video/mp4' && str_contains($format, 'mp4') && ($visual['codec_name'] ?? '') === 'h264' && ($visual['pix_fmt'] ?? '') === 'yuv420p' && (! $audio || ($audio['codec_name'] ?? '') === 'aac'),
        ];
    }

    public function thumbnail(Video $video): string
    {
        $disk = Storage::disk(config('video.disk'));
        $disk->makeDirectory('thumbnails');
        $relative = 'thumbnails/'.$video->uuid.'.jpg';
        $process = new Process([config('video.ffmpeg'), '-nostdin', '-v', 'error', '-threads', '1', '-protocol_whitelist', 'file', '-ss', (string) min(5, $video->duration_seconds / 3), '-i', $disk->path($video->storage_path), '-frames:v', '1', '-vf', 'scale=640:-2', '-threads', '1', '-y', $disk->path($relative)]);
        $process->setTimeout(config('video.process_timeout'))->mustRun();

        if (! chmod($disk->path($relative), 0600)) {
            throw new \RuntimeException('Cannot protect thumbnail.');
        }

        return $relative;
    }
}
