@props(['video', 'duration' => false])
<div {{ $attributes->class('video-meta') }}>
    <time datetime="{{ $video->created_at->toDateString() }}">{{ $video->created_at->format('j M Y') }}</time>
    @if($duration && $video->duration_seconds !== null)<span class="metadata-dot" aria-hidden="true">·</span><span><x-ui.icon name="clock" /> {{ $video->duration_label }}</span>@endif
</div>
