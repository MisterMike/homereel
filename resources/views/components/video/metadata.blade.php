@props(['video', 'duration' => false])
<div {{ $attributes->class('video-meta') }} x-data="{{ Js::from(['videoId' => $video->uuid, 'viewCount' => $video->view_count]) }}" @video-view-recorded.window="if ($event.detail.videoId === videoId) viewCount = $event.detail.count">
    @if($video->album)<span><x-ui.icon name="album" />{{ $video->album->name }}</span><span class="metadata-dot" aria-hidden="true">·</span>@endif
    <time datetime="{{ $video->created_at->toDateString() }}">{{ $video->created_at->translatedFormat('j. F Y') }}</time>
    @if($duration && $video->duration_seconds !== null)<span class="metadata-dot" aria-hidden="true">·</span><span><x-ui.icon name="clock" /> {{ $video->duration_label }}</span>@endif
    <span class="metadata-dot" aria-hidden="true">·</span><span><x-ui.icon name="eye" /><span x-text="new Intl.NumberFormat('de').format(viewCount) + (viewCount === 1 ? ' Aufruf' : ' Aufrufe')">{{ number_format($video->view_count, 0, ',', '.') }} {{ $video->view_count === 1 ? 'Aufruf' : 'Aufrufe' }}</span></span>
</div>
