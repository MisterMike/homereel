@props(['video'])
@php
    $hasOptimized = $video->hasOptimizedCopy();
    $initialQuality = $hasOptimized ? 'optimized' : 'original';
    $sources = ['original' => route('videos.stream', ['video' => $video, 'quality' => 'original'])];
    if ($hasOptimized) {
        $sources['optimized'] = route('videos.stream', ['video' => $video, 'quality' => 'optimized']);
    }
@endphp
<div x-data="videoPlayer({ sources: {{ Js::from($sources) }}, initialQuality: '{{ $initialQuality }}' })">
<section class="video-stage" :class="{ 'has-playback-error': failed }" aria-label="Video player">
    <video x-ref="video" class="video-player" controls playsinline preload="metadata" aria-label="{{ $video->title }}" @if($video->thumbnail_path) poster="{{ route('videos.thumbnail', $video) }}" @endif src="{{ $sources[$initialQuality] }}" @loadedmetadata="restorePlayback()" @loadstart="loading = true" @loadeddata="loading = false" @canplay="loading = false" @playing="loading = false; failed = false" @waiting="loading = true" x-on:error="loading = false; failed = true">
        Your browser does not support this video player.
    </video>
    <p class="video-loading" x-show="loading && !failed" x-cloak role="status">Loading video…</p>
    <div class="video-playback-error" x-show="failed" x-cloak role="alert">
        <x-ui.icon name="warning" /><h2>This video couldn’t be played.</h2>
        <p>The connection may have been interrupted, or your browser may not support this original. Try again, or ask your family administrator for help.</p>
        <button class="hr-button hr-button-secondary" type="button" @click="retry()">Try again</button>
    </div>
</section>

@if($hasOptimized)
    <div class="video-quality" x-cloak>
        <div class="video-quality-options" role="group" aria-label="Playback quality">
            <button type="button" :aria-pressed="quality === 'optimized'" @click="switchQuality('optimized')">Optimized <span>{{ $video->optimized_height }}p</span></button>
            <button type="button" :aria-pressed="quality === 'original'" @click="switchQuality('original')">Original @if($video->height)<span>{{ $video->height }}p</span>@endif</button>
        </div>
        <p>Optimized uses less bandwidth for most videos. Original keeps the uploaded quality.</p>
    </div>
@endif
<p class="sr-only" role="status" x-text="playbackMessage"></p>
</div>
