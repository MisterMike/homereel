<?php

namespace App\Models;

use App\Enums\UploadStatus;
use Illuminate\Database\Eloquent\Model;

class UploadSession extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $attributes = ['offset' => 0];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => UploadStatus::class, 'expires_at' => 'datetime', 'expected_size' => 'integer', 'offset' => 'integer', 'chunk_bytes' => 'integer'];
    }
}
