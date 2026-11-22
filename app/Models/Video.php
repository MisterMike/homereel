<?php

namespace App\Models;

use App\Enums\VideoStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Video extends Model
{
    use SoftDeletes;

    protected $attributes = ['prototype_available' => false, 'browser_compatible' => false];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => VideoStatus::class, 'prototype_available' => 'boolean', 'browser_compatible' => 'boolean', 'file_size' => 'integer', 'duration_seconds' => 'float', 'thumbnail_offset_seconds' => 'float'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->is_admin) {
            $query->where('prototype_available', true)->where('status', VideoStatus::Ready);
        }
    }

    public function getDurationLabelAttribute(): string
    {
        if ($this->duration_seconds === null) {
            return '—';
        }
        $seconds = (int) $this->duration_seconds;

        return ($seconds >= 3600 ? floor($seconds / 3600).':' : '').sprintf($seconds >= 3600 ? '%02d:%02d' : '%d:%02d', floor($seconds / 60) % 60, $seconds % 60);
    }
}
