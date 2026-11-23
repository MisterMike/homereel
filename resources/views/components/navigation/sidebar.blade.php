@props(['albums', 'videoCount', 'activeAlbumId' => null])
<aside class="hr-sidebar">
    <nav class="hr-sidebar-navigation" aria-label="Video albums">
        <a class="hr-nav-link {{ request()->routeIs('videos.index') && !$activeAlbumId ? 'is-current' : '' }}" href="{{ route('videos.index') }}" @if(request()->routeIs('videos.index') && !$activeAlbumId) aria-current="page" @endif><x-ui.icon name="library" /><span class="hr-nav-label">All videos</span><span class="hr-nav-count">{{ number_format($videoCount) }}</span></a>
        @foreach($albums as $navigationAlbum)
            <a class="hr-nav-link {{ $activeAlbumId === $navigationAlbum->id ? 'is-current' : '' }}" href="{{ route('videos.index', ['album' => $navigationAlbum->id]) }}" @if($activeAlbumId === $navigationAlbum->id) aria-current="page" @endif><x-ui.icon name="album" /><span class="hr-nav-label">{{ $navigationAlbum->name }}</span><span class="hr-nav-count">{{ number_format($navigationAlbum->visible_videos_count) }}</span></a>
        @endforeach
        @if(auth()->user()->canUploadVideos())
            <a class="hr-button hr-sidebar-upload" href="{{ route('filament.admin.pages.upload-video') }}"><x-ui.icon name="upload" /> Upload video</a>
        @endif
    </nav>
    <div class="hr-sidebar-note">
        @if(auth()->user()->canUploadVideos())<a class="hr-sidebar-manage" href="{{ route('filament.admin.resources.videos.index') }}"><x-ui.icon name="settings" /> Manage videos</a>@endif
        <x-ui.icon name="lock" /><p>For your family.<br>Just your family.</p><span>Your moments, kept close.</span>
    </div>
</aside>
