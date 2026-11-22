<?php

namespace App\Console\Commands;

use App\Enums\UploadStatus;
use App\Models\UploadSession;
use App\Services\Video\MediaLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CleanupUploads extends Command
{
    protected $signature = 'uploads:cleanup';

    protected $description = 'Remove expired temporary uploads, preserving completed originals';

    public function handle(MediaLock $lock): int
    {
        $count = 0;
        UploadSession::where('status', UploadStatus::Active)->where('expires_at', '<=', now())->each(function ($upload) use ($lock, &$count) {
            try {
                $lock->run($upload->id, function () use ($upload, &$count) {
                    $upload->refresh();
                    if ($upload->status !== UploadStatus::Active || $upload->expires_at->isFuture()) {
                        return;
                    }
                    Storage::disk(config('video.disk'))->delete($upload->temporary_path);
                    // A recovered finalization may have moved the original before a DB failure.
                    // Never delete that only copy automatically; report it for operator recovery.
                    if (Storage::disk(config('video.disk'))->exists('originals/'.$upload->id)) {
                        $this->warn('Retained uncommitted original for upload '.$upload->id.'. Extend expiry and retry finalization after reviewing logs.');

                        return;
                    }
                    $upload->update(['status' => UploadStatus::Expired]);
                    $count++;
                });
            } catch (HttpException $e) {
                if ($e->getStatusCode() !== 409) {
                    throw $e;
                }
            }
        });
        $this->info("Cleaned $count expired uploads.");

        return self::SUCCESS;
    }
}
