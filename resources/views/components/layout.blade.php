@props(['title' => 'Family videos'])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>{{ $title }} · HomeReel</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="home-app {{ auth()->check() ? 'is-authenticated' : 'is-guest' }}">
    <a class="hr-skip-link" href="#main-content">Skip to content</a>
    <x-navigation.header />
    @auth <x-navigation.sidebar /> @endauth
    <div class="hr-content-area">
        <main id="main-content" class="hr-main" tabindex="-1">{{ $slot }}</main>
        <footer class="hr-footer"><span>HomeReel</span><span><x-ui.icon name="lock" /> A little more private. A little more personal.</span></footer>
    </div>
</body>
</html>
