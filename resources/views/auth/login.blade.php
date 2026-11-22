<x-layout title="Sign in">
<div class="login-layout">
    <div class="login-intro"><h1>A place for<br>your shared stories.</h1><p>The little moments. The big occasions.<br>Your family videos, kept close.</p></div>
    <section class="login-form" aria-labelledby="login-heading"><h2 id="login-heading">Welcome back</h2><p>Sign in to your family library.</p>
        <form method="POST" action="{{ route('login') }}">@csrf
            <label for="email">Email address</label><input class="text-input" id="email" name="email" type="email" autocomplete="username" value="{{ old('email') }}" required autofocus>
            <label for="password">Password</label><input class="text-input" id="password" name="password" type="password" autocomplete="current-password" required>
            @if($errors->any())<p class="form-error" role="alert">{{ $errors->first() }}</p>@endif
            <button class="button" type="submit">Sign in</button>
        </form>
        <p class="access-note">Access is private. If you need an account, contact your family administrator.</p>
    </section>
</div>
</x-layout>
