<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserManagement
{
    /** @param array{name: string, role: string, is_active: bool} $data */
    public function update(User $actor, User $user, array $data): User
    {
        Gate::forUser($actor)->authorize('update', $user);
        $data = validator($data, [
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::in(array_keys(User::roles()))],
            'is_active' => ['required', 'boolean'],
        ])->validate();

        return DB::transaction(function () use ($actor, $user, $data): User {
            $administrators = User::where('is_admin', true)->orderBy('id')->lockForUpdate()->get();
            $user = User::lockForUpdate()->findOrFail($user->id);
            if ($actor->id === $user->id && ($data['role'] !== 'administrator' || ! $data['is_active'])) {
                throw ValidationException::withMessages(['role' => 'Du kannst deinen eigenen Administratorzugang nicht entfernen. Bitte einen anderen Administrator darum.']);
            }
            if ($user->is_admin && $user->is_active && ($data['role'] !== 'administrator' || ! $data['is_active']) && $administrators->where('is_active', true)->whereNull('invitation_token')->count() <= 1) {
                throw ValidationException::withMessages(['role' => 'Mindestens ein aktiver Administrator muss erhalten bleiben.']);
            }
            $user->forceFill([
                'name' => $data['name'], 'is_active' => $data['is_active'],
                'is_admin' => $data['role'] === 'administrator', 'is_contributor' => $data['role'] === 'contributor',
            ])->save();

            return $user;
        });
    }
}
