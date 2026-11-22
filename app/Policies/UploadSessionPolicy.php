<?php

namespace App\Policies;

use App\Models\UploadSession;
use App\Models\User;

class UploadSessionPolicy
{
    public function view(User $user, UploadSession $upload): bool
    {
        return $user->is_admin && $upload->user_id === $user->id;
    }

    public function update(User $user, UploadSession $upload): bool
    {
        return $this->view($user, $upload);
    }
}
