<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

class InvitationController extends Controller
{
    public function show(User $user, string $token): View
    {
        $this->assertValid($user, $token);

        return view('auth.invitation', compact('user', 'token'));
    }

    public function accept(Request $request, User $user, string $token): RedirectResponse
    {
        $this->assertValid($user, $token);
        $data = $request->validate(['password' => ['required', 'string', 'max:4096', 'confirmed', Password::min(12)]]);
        DB::transaction(function () use ($user, $token, $data): void {
            $user = User::lockForUpdate()->findOrFail($user->id);
            $this->assertValid($user, $token);
            $user->forceFill(['password' => $data['password'], 'email_verified_at' => now(), 'invitation_token' => null, 'invitation_expires_at' => null])->save();
        });
        Auth::login($user->fresh());
        $request->session()->regenerate();

        return redirect()->route('videos.index');
    }

    private function assertValid(User $user, string $token): void
    {
        abort_unless($user->is_active && $user->invitation_token !== null && $user->invitation_expires_at?->isFuture() && hash_equals($user->invitation_token, hash('sha256', $token)), 410, 'This invitation is invalid or expired. Ask your administrator to resend it.');
    }
}
