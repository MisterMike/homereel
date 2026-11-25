<?php

namespace App\Console\Commands;

use App\Models\Video;
use App\Services\Video\MediaLock;
use App\Services\Video\VideoOptimizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
            try {
                $lock->run('optimization-'.$video->uuid, function () use ($video, $lock) {
                    $lock->run('web-optimization-'.$video->uuid, function () use ($video, $lock): void {
                        $lock->run($video->uuid, function () use ($video) {
                            $video->refresh();
                            if (! $video->trashed()) {
                                return;
                            }
                            $disk = Storage::disk(config('video.disk'));
                            $paths = [$video->storage_path, $video->thumbnail_path, $video->optimized_path, $video->web_path];
                            if ($video->optimization_token) {
                                $paths[] = app(VideoOptimizer::class)->temporaryPath($video, $video->optimization_token);
                                $paths[] = app(VideoOptimizer::class)->finalPath($video, $video->optimization_token);
                            }
                            if ($video->web_token) {
                                $paths[] = app(VideoOptimizer::class)->temporaryPath($video, $video->web_token, standard: true);
                                $paths[] = app(VideoOptimizer::class)->finalPath($video, $video->web_token, standard: true);
                            }
                            if (! $disk->delete(array_filter($paths))) {
                                throw new \RuntimeException('Some media files could not be purged; the database record was retained.');
                            }
                            $video->forceDelete();
                        });
                    });
                });
            } catch (HttpException $exception) {
                if ($exception->getStatusCode() !== 409) {
                    throw $exception;
                }
                $this->warn('Skipped busy video '.$video->uuid.'. Retry after processing finishes.');
            }
        });
        $this->info('Eligible deleted videos purged.');

        return self::SUCCESS;
    }
}
