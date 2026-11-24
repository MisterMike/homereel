@props(['albums', 'videoCount', 'activeAlbumId' => null])
<aside class="hr-sidebar">
    <nav class="hr-sidebar-navigation" aria-label="Videoalben">
        <a class="hr-nav-link {{ request()->routeIs('videos.index') && !$activeAlbumId ? 'is-current' : '' }}" href="{{ route('videos.index') }}" @if(request()->routeIs('videos.index') && !$activeAlbumId) aria-current="page" @endif><x-ui.icon name="library" /><span class="hr-nav-label">Alle Videos</span><span class="hr-nav-count">{{ number_format($videoCount, 0, ',', '.') }}</span></a>
        @foreach($albums as $navigationAlbum)
            <a class="hr-nav-link {{ $activeAlbumId === $navigationAlbum->id ? 'is-current' : '' }}" href="{{ route('videos.index', ['album' => $navigationAlbum->id]) }}" @if($activeAlbumId === $navigationAlbum->id) aria-current="page" @endif><x-ui.icon name="album" /><span class="hr-nav-label">{{ $navigationAlbum->name }}</span><span class="hr-nav-count">{{ number_format($navigationAlbum->visible_videos_count, 0, ',', '.') }}</span></a>
        @endforeach
        @if(auth()->user()->canUploadVideos())
            <a class="hr-button hr-sidebar-upload" href="{{ route('filament.admin.pages.upload-video') }}"><x-ui.icon name="upload" /> Video hochladen</a>
        @endif
    </nav>
    <div class="hr-sidebar-note">
        @if(auth()->user()->canUploadVideos())<a class="hr-sidebar-manage" href="{{ route('filament.admin.resources.videos.index') }}"><x-ui.icon name="settings" /> Videos verwalten</a>@endif
        <x-ui.icon name="lock" /><p>Für deine Familie.<br>Nur für deine Familie.</p><span>Deine Momente, ganz nah.</span>
    </div>
</aside>
