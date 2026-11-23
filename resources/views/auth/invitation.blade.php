<x-layout title="Accept invitation">
    <div class="hr-login-layout">
        <div class="hr-login-intro">
            <h1>Your family’s stories.<br>A place to reconnect.</h1>
            <p>You’ve been invited to the shared HomeReel library.</p>
        </div>
        <section class="hr-login-form" aria-labelledby="invitation-heading">
            <h2 id="invitation-heading">Welcome, {{ $user->name }}.</h2>
            <p>Create a password for {{ $user->email }}. Your role is {{ strtolower(\App\Models\User::roles()[$user->role]) }}.</p>
            <form method="POST" action="{{ route('invitations.accept', ['user' => $user, 'token' => $token]) }}">@csrf
                <label for="password">Password (at least 12 characters)</label>
                <input class="hr-input" id="password" name="password" type="password" autocomplete="new-password" minlength="12" required autofocus @if($errors->any()) aria-describedby="invitation-error" aria-invalid="true" @endif>
                <label for="password_confirmation">Confirm password</label>
                <input class="hr-input" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required>
                @if($errors->any())<p id="invitation-error" class="hr-form-error" role="alert">{{ $errors->first() }}</p>@endif
                <button class="hr-button" type="submit">Accept invitation</button>
            </form>
        </section>
    </div>
</x-layout>
