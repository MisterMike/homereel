<?php

namespace App\Models;

use App\Enums\VideoStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Video extends Model
{
    use SoftDeletes;

    protected $attributes = ['prototype_available' => false, 'browser_compatible' => false, 'view_count' => 0];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => VideoStatus::class, 'prototype_available' => 'boolean', 'browser_compatible' => 'boolean', 'file_size' => 'integer', 'duration_seconds' => 'float', 'thumbnail_offset_seconds' => 'float', 'optimized_file_size' => 'integer', 'optimization_reserved_bytes' => 'integer', 'view_count' => 'integer', 'recorded_on' => 'date', 'optimization_profile' => 'array'];
    }

    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    public function hasOptimizedCopy(): bool
    {
        return $this->optimization_status === 'ready' && filled($this->optimized_path);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->is_admin) {
            $query->where(function (Builder $query) use ($user): void {
                $query->where(function (Builder $query): void {
                    $query->where('prototype_available', true)->where('status', VideoStatus::Ready);
                });
                if ($user->canUploadVideos()) {
                    $query->orWhere('uploaded_by', $user->id);
                }
            });
        }
    }

    public function scopeByRecordingDate(Builder $query, string $direction = 'desc'): void
    {
        $direction = $direction === 'asc' ? 'asc' : 'desc';
        $query->orderByRaw('COALESCE(recorded_on, DATE(created_at)) '.$direction)
            ->orderBy('created_at', $direction)->orderBy('id', $direction);
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
