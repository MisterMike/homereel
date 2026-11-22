<?php

namespace App\Console\Commands;

use App\Models\Video;
use App\Services\Video\MediaLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PurgeVideos extends Command
{
    protected $signature = 'videos:purge {--days=30 : Minimum days since deletion} {--force : Confirm permanent original deletion}';

    protected $description = 'Permanently purge soft-deleted videos after backup verification';

    public function handle(MediaLock $lock): int
    {
        if (! $this->option('force') || ! ctype_digit((string) $this->option('days')) || (int) $this->option('days') < 1) {
            $this->error('Verify backups, then pass --force and --days of at least 1.');

            return self::FAILURE;
        }
        Video::onlyTrashed()->where('deleted_at', '<=', now()->subDays((int) $this->option('days')))->each(function ($video) use ($lock) {
            $lock->run($video->uuid, function () use ($video) {
                $video->refresh();
                if (! $video->trashed()) {
                    return;
                }
                $disk = Storage::disk(config('video.disk'));
                $disk->delete(array_filter([$video->storage_path, $video->thumbnail_path]));
                $video->forceDelete();
            });
        });
        $this->info('Eligible deleted videos purged.');

        return self::SUCCESS;
    }
}
