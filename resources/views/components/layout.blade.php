<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>{{ $title ?? 'Family videos' }} · HomeReel</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="home-app">
    <header class="site-header"><a class="wordmark" href="{{ route('videos.index') }}" aria-label="HomeReel home"><span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="18" height="18" rx="5" stroke="currentColor" stroke-width="1.5"/><path d="m10 8 6 4-6 4V8Z" fill="currentColor"/></svg></span>HomeReel</a>
    @auth
        <nav aria-label="Main navigation"><a href="{{ route('videos.index') }}">Library</a>@if(auth()->user()->is_admin)<a href="/admin">Manage videos</a>@endif<form method="POST" action="{{ route('logout') }}">@csrf<button class="text-button" type="submit">Sign out</button></form></nav>
    @else <span class="private-label">Your private family library</span> @endauth
    </header>
    <main class="page-shell">{{ $slot }}</main>
    <footer class="site-footer">HomeReel <span>For your family. Just your family.</span></footer>
</body>
</html>
