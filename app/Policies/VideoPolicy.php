<?php

namespace App\Policies;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;

class VideoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canUploadVideos();
    }

    public function view(User $user, Video $video): bool
    {
        return $user->is_active && ($user->is_admin || ($user->canUploadVideos() && $video->uploaded_by === $user->id) || ($video->prototype_available && $video->status === VideoStatus::Ready));
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Video $video): bool
    {
        return $user->canUploadVideos() && ($user->is_admin || $video->uploaded_by === $user->id);
    }

    public function delete(User $user, Video $video): bool
    {
        return $this->update($user, $video);
    }
}
