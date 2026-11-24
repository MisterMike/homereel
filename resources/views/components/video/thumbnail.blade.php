@props(['video', 'eager' => false])
<div {{ $attributes->class('video-cover') }} x-data="mediaThumbnail" :class="{ 'has-preview': loaded && !failed }">
    <div class="video-placeholder"><x-ui.icon name="film" /><span>{{ $video->status === \App\Enums\VideoStatus::Ready ? 'Noch kein Vorschaubild' : 'Vorschaubild nicht verfügbar' }}</span></div>
    @if($video->thumbnail_path)
        <img x-ref="image" src="{{ route('videos.thumbnail', $video) }}" alt="" loading="{{ $eager ? 'eager' : 'lazy' }}" decoding="async" width="640" height="360" @load="loaded = true" x-on:error="failed = true" x-show="!failed" :class="{ 'is-loaded': loaded }">
        <span class="video-image-loading" x-show="!loaded && !failed" aria-hidden="true"></span>
    @endif
    <span class="video-play-hint"><x-ui.icon name="play" /></span>
</div>
