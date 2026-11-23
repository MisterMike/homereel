@props(['albums', 'activeAlbumId' => null, 'search' => ''])
<header class="hr-header">
    <x-ui.brand />
    @auth
        <nav class="hr-top-nav" aria-label="Main navigation">
            <a class="hr-top-link" href="{{ route('videos.index') }}" @if(request()->routeIs('videos.*') && !$activeAlbumId) aria-current="page" @endif><x-ui.icon name="play" /> Videos</a>
            <div class="hr-albums-menu" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.prevent.stop="open = false; $refs.trigger.focus()">
                <button class="hr-top-link {{ $activeAlbumId ? 'is-current' : '' }}" type="button" x-ref="trigger" @click="open = !open" :aria-expanded="open.toString()" aria-controls="albums-menu"><x-ui.icon name="album" /> Albums <x-ui.icon class="hr-menu-chevron" name="chevron" /></button>
                <div class="hr-navigation-menu" id="albums-menu" x-show="open" x-cloak>
                    <a href="{{ route('videos.index') }}">All videos</a>
                    @forelse($albums as $navigationAlbum)
                        <a href="{{ route('videos.index', ['album' => $navigationAlbum->id]) }}" @if($activeAlbumId === $navigationAlbum->id) aria-current="page" @endif><span>{{ $navigationAlbum->name }}</span><span class="hr-nav-count">{{ number_format($navigationAlbum->visible_videos_count) }}</span></a>
                    @empty
                        <p>No albums with videos yet.</p>
                    @endforelse
                    @if(auth()->user()->is_admin)<a class="hr-menu-manage" href="{{ route('filament.admin.resources.albums.index') }}"><x-ui.icon name="settings" /> Manage albums</a>@endif
                </div>
            </div>
            @if(auth()->user()->is_admin)<a class="hr-top-link" href="{{ route('filament.admin.resources.users.index') }}"><x-ui.icon name="people" /> People</a>@endif
        </nav>
        <form class="hr-header-search" role="search" method="GET" action="{{ route('videos.index') }}">
            @if($activeAlbumId)<input type="hidden" name="album" value="{{ $activeAlbumId }}">@endif
            <input id="library-search" name="q" type="search" value="{{ $search }}" maxlength="255" placeholder="Search videos and moments…" aria-label="Search videos">
            <button type="submit" aria-label="Search videos"><x-ui.icon name="search" /></button>
        </form>
        <div class="hr-account" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.prevent.stop="open = false; $refs.trigger.focus()">
            <button class="hr-account-trigger" type="button" x-ref="trigger" @click="open = !open" :aria-expanded="open.toString()" aria-controls="account-menu" aria-label="Account menu">
                <span class="hr-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                <span class="hr-account-name">{{ auth()->user()->name }}</span><x-ui.icon name="chevron" />
            </button>
            <div id="account-menu" class="hr-account-menu" x-show="open" x-cloak>
                <p class="hr-account-identity">{{ auth()->user()->name }}<span>{{ auth()->user()->email }}</span></p>
                @if(auth()->user()->canUploadVideos())
                    <a href="{{ route('filament.admin.resources.videos.index') }}"><x-ui.icon name="settings" /> Manage videos</a>
                    <a href="{{ route('filament.admin.pages.upload-video') }}"><x-ui.icon name="upload" /> Upload video</a>
                @endif
                @if(auth()->user()->is_admin)<a href="{{ route('filament.admin.resources.users.index') }}"><x-ui.icon name="people" /> Manage people</a>@endif
                <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit"><x-ui.icon name="logout" /> Sign out</button></form>
            </div>
        </div>
    @else
        <span class="hr-private-label"><x-ui.icon name="lock" /> Your private family cinema</span>
    @endauth
</header>
