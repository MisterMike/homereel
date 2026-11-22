<?php

namespace App\Contracts;

use App\Models\UploadSession;
use App\Models\User;
use App\Models\Video;

interface ResumableUploads
{
    public function create(User $user, array $data): UploadSession;

    public function append(UploadSession $upload, int $offset, int $length, string $checksum, mixed $input): UploadSession;

    public function finish(UploadSession $upload): Video;
}
