@props(['video'])
<article class="video-card">
    <a class="video-card-link" href="{{ route('videos.show', $video) }}">
        <div class="video-card-image">
            <x-video.thumbnail :video="$video" />
            @if($video->duration_seconds !== null)<span class="video-duration">{{ $video->duration_label }}</span>@endif
            @if($video->status !== \App\Enums\VideoStatus::Ready)<span class="video-status" data-status="{{ $video->status->value }}">{{ ucfirst($video->status->value) }}</span>@endif
        </div>
        <h3>{{ $video->title }}</h3>
        <x-video.metadata :video="$video" />
    </a>
</article>
