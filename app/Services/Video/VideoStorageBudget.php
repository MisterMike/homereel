<?php

namespace App\Services\Video;

use App\Enums\UploadStatus;
use App\Models\UploadSession;
use App\Models\Video;

class VideoStorageBudget
{
    public function used(): int
    {
        return (int) Video::withTrashed()->sum('file_size')
            + (int) Video::withTrashed()->sum('optimized_file_size')
            + (int) Video::withTrashed()->sum('optimization_reserved_bytes')
            + (int) Video::withTrashed()->sum('web_file_size')
            + (int) Video::withTrashed()->sum('web_reserved_bytes')
            + (int) UploadSession::where('status', UploadStatus::Active)->sum('expected_size');
    }
}
