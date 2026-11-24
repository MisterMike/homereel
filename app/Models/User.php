<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    use HasFactory, Notifiable;

    protected $attributes = ['is_admin' => false, 'is_contributor' => false, 'is_active' => true];

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token', 'invitation_token', 'invitation_expires_at'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'is_admin' => 'boolean', 'is_contributor' => 'boolean', 'is_active' => 'boolean', 'invitation_expires_at' => 'datetime', 'email_verified_at' => 'datetime'];
    }

    public function canUploadVideos(): bool
    {
        return $this->is_active && $this->invitation_token === null && ($this->is_admin || $this->is_contributor);
    }

    public function getRoleAttribute(): string
    {
        return $this->is_admin ? 'administrator' : ($this->is_contributor ? 'contributor' : 'viewer');
    }

    /** @return array<string, string> */
    public static function roles(): array
    {
        return ['viewer' => 'Zuschauer', 'contributor' => 'Mitwirkender', 'administrator' => 'Administrator'];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->canUploadVideos();
    }
}
