<?php

namespace App\Services\Video;

use App\Contracts\VideoDelivery;
use App\Enums\VideoStatus;
use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class LocalDelivery implements VideoDelivery
{
    public function stream(Video $video, Request $request): Response
    {
        abort_unless($video->status === VideoStatus::Ready, 409, 'Video is not ready.');

        return $this->deliver($video->storage_path, $video->mime_type, $request);
    }

    public function thumbnail(Video $video, Request $request): Response
    {
        abort_unless($video->thumbnail_path, 404);

        return $this->deliver($video->thumbnail_path, 'image/jpeg', $request);
    }

    private function deliver(string $path, string $mime, Request $request): Response
    {
        $disk = Storage::disk(config('video.disk'));
        abort_unless($disk->exists($path), 404);
        $size = $disk->size($path);
        $start = 0;
        $end = $size - 1;
        $status = 200;
        $headers = ['Accept-Ranges' => 'bytes', 'Content-Type' => $mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline'];
        // RFC 9110: Range is evaluated for GET only. HEAD describes a normal GET.
        $range = $request->isMethod('GET') ? $request->header('Range') : null;
        // No validators are advertised, so If-Range cannot match a strong validator.
        if ($range && ! $request->hasHeader('If-Range')) {
            if (! preg_match('/^bytes=(\d*)-(\d*)$/D', $range, $parts) || ($parts[1] === '' && $parts[2] === '') || $size === 0) {
                return new Response('', 416, $headers + ['Content-Range' => "bytes */$size", 'Content-Length' => '0']);
            }
            if ($parts[1] === '') {
                $suffix = (int) $parts[2];
                $start = max(0, $size - $suffix);
            } else {
                $start = (int) $parts[1];
                if ($parts[2] !== '') {
                    $end = min($end, (int) $parts[2]);
                }
            }
            if ($start >= $size || $end < $start || ($parts[1] === '' && (int) $parts[2] === 0)) {
                return new Response('', 416, $headers + ['Content-Range' => "bytes */$size", 'Content-Length' => '0']);
            }
            $status = 206;
            $headers['Content-Range'] = "bytes $start-$end/$size";
        }
        $length = max(0, $end - $start + 1);
        $headers['Content-Length'] = (string) $length;
        if ($request->isMethod('HEAD')) {
            return new Response('', 200, $headers);
        }

        return response()->stream(function () use ($disk, $path, $start, $length) {
            $stream = $disk->readStream($path);
            try {
                if (fseek($stream, $start) !== 0) {
                    throw new \RuntimeException('Cannot seek video.');
                }
                $remaining = $length;
                while ($remaining > 0 && ! feof($stream) && ! connection_aborted()) {
                    $data = fread($stream, min(65536, $remaining));
                    if ($data === false || $data === '') {
                        break;
                    }
                    $remaining -= strlen($data);
                    echo $data;
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }
            } finally {
                fclose($stream);
            }
        }, $status, $headers);
    }
}
