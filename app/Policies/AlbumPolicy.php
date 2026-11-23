<?php

namespace App\Policies;

use App\Models\Album;
use App\Models\User;

class AlbumPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->is_admin;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Album $record): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, Album $record): bool
    {
        return $this->viewAny($user);
    }
}
