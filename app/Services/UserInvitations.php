<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\LibraryInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserInvitations
{
    /** @param array{name: string, email: string, role: string} $data */
    public function invite(User $actor, array $data): User
    {
        Gate::forUser($actor)->authorize('create', User::class);
        $data['email'] = Str::lower(trim($data['email'] ?? ''));
        $data = validator($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(array_keys(User::roles()))],
        ])->validate();

        return DB::transaction(function () use ($data): User {
            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => Str::random(64)]);
            $user->forceFill(['is_admin' => $data['role'] === 'administrator', 'is_contributor' => $data['role'] === 'contributor'])->save();
            $this->send($user);

            return $user;
        });
    }

    public function resend(User $actor, User $user): void
    {
        Gate::forUser($actor)->authorize('update', $user);
        DB::transaction(function () use ($user): void {
            $user = User::lockForUpdate()->findOrFail($user->id);
            abort_unless($user->is_active && $user->invitation_token !== null, 409, 'Nur aktive, offene Einladungen können erneut gesendet werden.');
            $this->send($user);
        });
    }

    private function send(User $user): void
    {
        $token = Str::random(64);
        $user->forceFill(['invitation_token' => hash('sha256', $token), 'invitation_expires_at' => now()->addDays(7)])->save();
        $user->notify(new LibraryInvitation(route('invitations.show', ['user' => $user->id, 'token' => $token])));
    }
}
