<aside class="hr-sidebar">
    <nav aria-label="Main navigation">
        <a class="hr-nav-link is-current" href="{{ route('videos.index') }}" @if(request()->routeIs('videos.index')) aria-current="page" @endif><x-ui.icon name="library" /> All videos</a>
        @if(auth()->user()->is_admin)
            <a class="hr-nav-link" href="{{ route('filament.admin.pages.dashboard') }}"><x-ui.icon name="settings" /> Manage videos</a>
            <a class="hr-button hr-sidebar-upload" href="{{ route('filament.admin.pages.upload-video') }}"><x-ui.icon name="upload" /> Upload video</a>
        @endif
    </nav>
    <div class="hr-sidebar-note"><x-ui.icon name="lock" /><p>For your family.<br>Just your family.</p><span>Your moments, kept close.</span></div>
</aside>
