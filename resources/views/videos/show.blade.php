<x-layout :title="$video->title">
    <a class="hr-back-link" href="{{ route('videos.index') }}"><x-ui.icon name="back" /> Back to library</a>
    @if($video->status === \App\Enums\VideoStatus::Ready)
        <x-video.player :video="$video" />
    @else
        <section class="video-processing" role="status" aria-labelledby="video-status-heading">
            <x-ui.icon :name="$video->status === \App\Enums\VideoStatus::Failed ? 'warning' : 'film'" />
            <h2 id="video-status-heading">{{ $video->status === \App\Enums\VideoStatus::Failed ? 'Video inspection failed' : 'Preparing your video' }}</h2>
            <p>{{ $video->processing_error ?? 'Your original is safely stored. Once inspection finishes, this is where you can press play.' }}</p>
            <a class="hr-button hr-button-secondary" href="{{ route('videos.show', $video) }}">Check again</a>
        </section>
    @endif
    <div class="video-detail-heading">
        <div><h1>{{ $video->title }}</h1><x-video.metadata :video="$video" :duration="true" /></div>
        <span class="hr-private-label"><x-ui.icon name="lock" /> Private video</span>
    </div>
    @if($video->status === \App\Enums\VideoStatus::Ready && !$video->browser_compatible && !$video->hasOptimizedCopy())
        <p class="hr-notice"><x-ui.icon name="warning" /> This original may not play in every browser. An optimized MP4 can improve compatibility. Ask your family administrator to prepare a copy.</p>
    @endif
    @if($video->description)<div class="video-description"><p>{{ $video->description }}</p></div>@endif
</x-layout>
