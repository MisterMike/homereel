@props(['video'])
<section class="video-feature" aria-label="Ein Moment zum Wiedererleben">
    <x-video.thumbnail :video="$video" :eager="true" class="feature-cover" />
    <div class="video-feature-content">
        <h2>{{ $video->title }}</h2>
        <x-video.metadata :video="$video" :duration="true" />
        @if($video->description)<p class="video-feature-description">{{ $video->description }}</p>@endif
        <a class="hr-button" href="{{ route('videos.show', $video) }}"><x-ui.icon name="play" /> Video ansehen<span class="sr-only">: {{ $video->title }}</span></a>
    </div>
</section>
