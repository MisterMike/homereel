<x-layout title="Your videos">
    @php
        $featured = $videos->currentPage() === 1
            ? $videos->getCollection()->first(fn ($video) => $video->status === \App\Enums\VideoStatus::Ready && $video->thumbnail_path)
            : null;
    @endphp
    <div class="hr-page-heading">
        <div><h1>Your videos</h1><p>Your family’s moments, ready to revisit.</p></div>
        @if(auth()->user()->is_admin)<a class="hr-button hr-mobile-upload" href="{{ route('filament.admin.pages.upload-video') }}"><x-ui.icon name="upload" /> Upload video</a>@endif
    </div>
    @if($featured)<x-video.feature :video="$featured" />@endif
    @if($videos->isNotEmpty())
        <section aria-labelledby="all-videos-heading">
            <div class="hr-section-heading"><h2 id="all-videos-heading">All videos</h2><span>{{ number_format($videos->total()) }} {{ $videos->total() === 1 ? 'video' : 'videos' }}</span></div>
            <div class="video-grid grid grid-cols-1 gap-x-6 gap-y-8 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @foreach($videos as $video)<x-video.card :video="$video" />@endforeach
            </div>
        </section>
    @else
        <x-ui.empty-state />
    @endif
    @if($videos->hasPages())<div class="hr-pagination">{{ $videos->links() }}</div>@endif
</x-layout>
