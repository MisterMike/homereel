@props(['video'])
<section class="video-stage" x-data="videoPlayer" :class="{ 'has-playback-error': failed }" aria-label="Video player">
    <video x-ref="video" class="video-player" controls playsinline preload="metadata" aria-label="{{ $video->title }}" @if($video->thumbnail_path) poster="{{ route('videos.thumbnail', $video) }}" @endif src="{{ route('videos.stream', $video) }}" @loadstart="loading = true" @loadeddata="loading = false" @canplay="loading = false" @playing="loading = false; failed = false" @waiting="loading = true" x-on:error="loading = false; failed = true">
        Your browser does not support this video player.
    </video>
    <p class="video-loading" x-show="loading && !failed" x-cloak role="status">Loading video…</p>
    <div class="video-playback-error" x-show="failed" x-cloak role="alert">
        <x-ui.icon name="warning" /><h2>This video couldn’t be played.</h2>
        <p>The connection may have been interrupted, or your browser may not support this original. Try again, or ask your family administrator for help.</p>
        <button class="hr-button hr-button-secondary" type="button" @click="retry()">Try again</button>
    </div>
</section>
