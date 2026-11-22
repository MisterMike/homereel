<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VideoConversionSetting extends Model
{
    protected $fillable = ['id', 'enabled', 'automatic'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'automatic' => 'boolean'];
    }
}
