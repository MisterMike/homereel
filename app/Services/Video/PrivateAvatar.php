<?php

namespace App\Services\Video;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Illuminate\Database\Eloquent\Model;

class PrivateAvatar implements AvatarProvider
{
    public function get(Model $record): string
    {
        return asset('images/avatar.svg');
    }
}
