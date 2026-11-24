<x-layout title="Anmelden">
    <div class="hr-login-layout">
        <div class="hr-login-intro">
            <div class="hr-login-reel" aria-hidden="true"><x-ui.icon name="play" /></div>
            <h1>Die Momente, die<br>uns verbinden.</h1>
            <p>Die Geschichten deiner Familie an einem Ort.<br>Film ab. Einander näher sein.</p>
        </div>
        <section class="hr-login-form" aria-labelledby="login-heading">
            <h2 id="login-heading">Willkommen zu Hause.</h2>
            <p>Melde dich bei der Video-Mediathek deiner Familie an.</p>
            <form method="POST" action="{{ route('login') }}">@csrf
                <label for="email">E-Mail-Adresse</label>
                <input class="hr-input" id="email" name="email" type="email" autocomplete="username" value="{{ old('email') }}" required autofocus @if($errors->any()) aria-invalid="true" aria-describedby="login-error" @endif>
                <label for="password">Passwort</label>
                <input class="hr-input" id="password" name="password" type="password" autocomplete="current-password" required @if($errors->any()) aria-describedby="login-error" @endif>
                @if($errors->any())<p id="login-error" class="hr-form-error" role="alert">{{ $errors->first() }}</p>@endif
                <button class="hr-button" type="submit">Anmelden <x-ui.icon name="play" /></button>
            </form>
            <p class="hr-access-note"><x-ui.icon name="lock" /> Der Zugang ist privat. Für ein Konto oder Hilfe bei der Anmeldung wende dich an die Person, die eure Mediathek verwaltet.</p>
        </section>
    </div>
</x-layout>
