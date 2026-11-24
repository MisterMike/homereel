<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VideoConversionSetting extends Model
{
    protected $fillable = ['id', 'enabled', 'automatic', 'crf', 'preset', 'max_height', 'video_bitrate', 'audio_bitrate'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'automatic' => 'boolean', 'crf' => 'integer', 'max_height' => 'integer', 'video_bitrate' => 'integer', 'audio_bitrate' => 'integer'];
    }
}
