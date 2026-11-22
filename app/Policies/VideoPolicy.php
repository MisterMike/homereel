<?php

namespace App\Policies;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;

class VideoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }

    public function view(User $user, Video $video): bool
    {
        return $user->is_admin || ($video->prototype_available && $video->status === VideoStatus::Ready);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Video $video): bool
    {
        return $user->is_admin;
    }

    public function delete(User $user, Video $video): bool
    {
        return $user->is_admin;
    }
}
