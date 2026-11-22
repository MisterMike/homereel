<x-layout title="Sign in">
    <div class="hr-login-layout">
        <div class="hr-login-intro">
            <div class="hr-login-reel" aria-hidden="true"><x-ui.icon name="play" /></div>
            <h1>The moments that<br>bring us together.</h1>
            <p>Your family’s stories, in one place.<br>Press play. Feel a little closer.</p>
        </div>
        <section class="hr-login-form" aria-labelledby="login-heading">
            <h2 id="login-heading">Welcome home.</h2>
            <p>Sign in to your family’s video library.</p>
            <form method="POST" action="{{ route('login') }}">@csrf
                <label for="email">Email address</label>
                <input class="hr-input" id="email" name="email" type="email" autocomplete="username" value="{{ old('email') }}" required autofocus @if($errors->any()) aria-invalid="true" aria-describedby="login-error" @endif>
                <label for="password">Password</label>
                <input class="hr-input" id="password" name="password" type="password" autocomplete="current-password" required @if($errors->any()) aria-describedby="login-error" @endif>
                @if($errors->any())<p id="login-error" class="hr-form-error" role="alert">{{ $errors->first() }}</p>@endif
                <button class="hr-button" type="submit">Sign in <x-ui.icon name="play" /></button>
            </form>
            <p class="hr-access-note"><x-ui.icon name="lock" /> Access is private. For an account or help signing in, contact your family administrator.</p>
        </section>
    </div>
</x-layout>
