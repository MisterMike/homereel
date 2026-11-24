@props(['title' => 'Familienvideos'])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>{{ $title }} · HomeReel</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="home-app {{ auth()->check() ? 'is-authenticated' : 'is-guest' }}">
    <a class="hr-skip-link" href="#main-content">Zum Inhalt springen</a>
    <x-navigation.header :albums="$navigationAlbums ?? collect()" :active-album-id="$activeAlbumId ?? null" :search="$librarySearch ?? ''" />
    @auth <x-navigation.sidebar :albums="$navigationAlbums" :video-count="$navigationVideoCount" :active-album-id="$activeAlbumId" /> @endauth
    <div class="hr-content-area">
        <main id="main-content" class="hr-main" tabindex="-1">{{ $slot }}</main>
        <footer class="hr-footer"><span>HomeReel</span><span><x-ui.icon name="lock" /> Ein bisschen privater. Ein bisschen persönlicher.</span></footer>
    </div>
</body>
</html>
