<x-layout title="Library">
    <div class="page-heading"><div><h1>Your family library</h1><p>Good times, ready to revisit.</p></div>@if(auth()->user()->is_admin)<a class="button" href="{{ \App\Filament\Pages\UploadVideo::getUrl() }}">Upload video</a>@endif</div>
    <div class="video-grid">
    @forelse($videos as $video)
        <a class="video-card" href="{{ route('videos.show', $video) }}">
            <div class="video-cover">@if($video->thumbnail_path)<img src="{{ route('videos.thumbnail', $video) }}" alt="" loading="lazy">@else<span class="cover-placeholder">{{ $video->status === \App\Enums\VideoStatus::Ready ? 'Ready to watch' : ucfirst($video->status->value) }}</span>@endif<span class="duration">{{ $video->duration_label }}</span></div>
            <h2>{{ $video->title }}</h2><p>{{ $video->created_at->format('j M Y') }}</p>
        </a>
    @empty
        <section class="empty-state"><h2>Your memories start here.</h2><p>{{ auth()->user()->is_admin ? 'Upload your first video. Once it has been inspected, make it available to family members in the administration panel.' : 'There are no videos available yet. Your family administrator will share them here.' }}</p></section>
    @endforelse
    </div>
    <div class="pagination">{{ $videos->links() }}</div>
</x-layout>
