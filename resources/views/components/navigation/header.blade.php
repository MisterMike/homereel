<header class="hr-header">
    <x-ui.brand />
    @auth
        <a class="hr-top-library" href="{{ route('videos.index') }}" @if(request()->routeIs('videos.*')) aria-current="page" @endif><x-ui.icon name="library" /> Library</a>
        <div class="hr-account" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.prevent.stop="open = false; $refs.trigger.focus()">
            <button class="hr-account-trigger" type="button" x-ref="trigger" @click="open = !open" :aria-expanded="open.toString()" aria-controls="account-menu" aria-label="Account menu">
                <span class="hr-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                <span class="hr-account-name">{{ auth()->user()->name }}</span><x-ui.icon name="chevron" />
            </button>
            <div id="account-menu" class="hr-account-menu" x-show="open" x-cloak>
                <p class="hr-account-identity">{{ auth()->user()->name }}<span>{{ auth()->user()->email }}</span></p>
                @if(auth()->user()->is_admin)
                    <a href="{{ route('filament.admin.pages.dashboard') }}"><x-ui.icon name="settings" /> Manage videos</a>
                    <a href="{{ route('filament.admin.pages.upload-video') }}"><x-ui.icon name="upload" /> Upload video</a>
                @endif
                <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit"><x-ui.icon name="logout" /> Sign out</button></form>
            </div>
        </div>
    @else
        <span class="hr-private-label"><x-ui.icon name="lock" /> Your private family cinema</span>
    @endauth
</header>
