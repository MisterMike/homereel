<x-layout :title="$video->title">
    <a class="back-link" href="{{ route('videos.index') }}">← Back to library</a>
    @if($video->status === \App\Enums\VideoStatus::Ready)
        <video class="video-player" controls playsinline preload="metadata" @if($video->thumbnail_path) poster="{{ route('videos.thumbnail', $video) }}" @endif src="{{ route('videos.stream', $video) }}">Your browser does not support this video player.</video>
        @unless($video->browser_compatible)<p class="notice">This original may not play in your browser. No playback conversion has been made. MP4 with H.264 video and AAC audio is recommended.</p>@endunless
    @else
        <div class="processing-state" role="status"><h2>{{ $video->status === \App\Enums\VideoStatus::Failed ? 'Video inspection failed' : 'Preparing your video' }}</h2><p>{{ $video->processing_error ?? 'The original is safely stored. Refresh this page after the queue worker has inspected it.' }}</p></div>
    @endif
    <div class="video-description"><h1>{{ $video->title }}</h1><p class="video-meta">{{ $video->created_at->format('j F Y') }} · {{ $video->duration_label }}</p>@if($video->description)<p class="description-text">{{ $video->description }}</p>@endif</div>
</x-layout>
