<x-layout title="Einladung annehmen">
    <div class="hr-login-layout">
        <div class="hr-login-intro">
            <h1>Die Geschichten deiner Familie.<br>Ein Ort, der verbindet.</h1>
            <p>Du wurdest zur gemeinsamen HomeReel-Mediathek eingeladen.</p>
        </div>
        <section class="hr-login-form" aria-labelledby="invitation-heading">
            <h2 id="invitation-heading">Willkommen, {{ $user->name }}.</h2>
            <p>Erstelle ein Passwort für {{ $user->email }}. Deine Rolle ist {{ \App\Models\User::roles()[$user->role] }}.</p>
            <form method="POST" action="{{ route('invitations.accept', ['user' => $user, 'token' => $token]) }}">@csrf
                <label for="password">Passwort (mindestens 12 Zeichen)</label>
                <input class="hr-input" id="password" name="password" type="password" autocomplete="new-password" minlength="12" required autofocus @if($errors->any()) aria-describedby="invitation-error" aria-invalid="true" @endif>
                <label for="password_confirmation">Passwort bestätigen</label>
                <input class="hr-input" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required>
                @if($errors->any())<p id="invitation-error" class="hr-form-error" role="alert">{{ $errors->first() }}</p>@endif
                <button class="hr-button" type="submit">Einladung annehmen</button>
            </form>
        </section>
    </div>
</x-layout>
