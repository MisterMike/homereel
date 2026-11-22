<?php

namespace App\Services\Video;

use Illuminate\Support\Facades\Storage;

class MediaLock
{
    public function run(string $key, callable $callback): mixed
    {
        $disk = Storage::disk(config('video.disk'));
        $disk->makeDirectory('locks');
        $handle = fopen($disk->path('locks/'.hash('sha256', $key).'.lock'), 'c');
        if (! $handle) {
            throw new \RuntimeException('Cannot open media lock.');
        }
        try {
            abort_unless(flock($handle, LOCK_EX | LOCK_NB), 409, 'This media is busy. Retry shortly.');

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
