<x-layout title="Deine Videos">
    @php
        $featured = $videos->currentPage() === 1 && $search === ''
            ? $videos->getCollection()->first(fn ($video) => $video->status === \App\Enums\VideoStatus::Ready && $video->thumbnail_path)
            : null;
    @endphp
    @if(auth()->user()->canUploadVideos())<div class="hr-mobile-library-actions"><a class="hr-button hr-mobile-upload" href="{{ route('filament.admin.pages.upload-video') }}"><x-ui.icon name="upload" /> Video hochladen</a></div>@endif
    @if($featured)<x-video.feature :video="$featured" />@endif
    <section aria-labelledby="all-videos-heading">
        <div class="hr-section-heading hr-library-heading">
            <h1 id="all-videos-heading">{{ $search !== '' ? 'Suchergebnisse' : ($album?->name ?? 'Alle Videos') }}</h1>
            <span>{{ number_format($videos->total(), 0, ',', '.') }} {{ $videos->total() === 1 ? 'Video' : 'Videos' }}</span>
        </div>
        @if($search !== '')<p class="hr-search-summary">Ergebnisse für “{{ $search }}”{{ $album ? ' in '.$album->name : '' }}. <a href="{{ route('videos.index', $album ? ['album' => $album->id] : []) }}">Suche zurücksetzen</a></p>@endif
        @if($videos->isNotEmpty())
            <div class="video-grid grid grid-cols-1 gap-x-6 gap-y-6 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @foreach($videos as $video)<x-video.card :video="$video" />@endforeach
            </div>
        @elseif($search !== '')
            <div class="hr-empty-state"><div class="hr-empty-symbol"><x-ui.icon name="search" /></div><h2>Keine passenden Videos gefunden.</h2><p>Versuche einen anderen Titel oder ein Wort aus der Beschreibung.</p><a class="hr-button hr-button-secondary" href="{{ route('videos.index', $album ? ['album' => $album->id] : []) }}">Suche zurücksetzen</a></div>
        @else
            <x-ui.empty-state />
        @endif
    </section>
    @if($videos->hasPages())<div class="hr-pagination">{{ $videos->links() }}</div>@endif
</x-layout>
