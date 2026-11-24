@props(['video', 'duration' => false])
<div {{ $attributes->class('video-meta') }}>
    @if($video->album)<span><x-ui.icon name="album" />{{ $video->album->name }}</span><span class="metadata-dot" aria-hidden="true">·</span>@endif
    <time datetime="{{ $video->created_at->toDateString() }}">{{ $video->created_at->translatedFormat('j. F Y') }}</time>
    @if($duration && $video->duration_seconds !== null)<span class="metadata-dot" aria-hidden="true">·</span><span><x-ui.icon name="clock" /> {{ $video->duration_label }}</span>@endif
</div>
