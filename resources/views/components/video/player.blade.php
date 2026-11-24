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
<section class="video-stage" :class="{ 'has-playback-error': failed }" aria-label="Videoplayer">
    <video x-ref="video" class="video-player" controls playsinline preload="metadata" aria-label="{{ $video->title }}" @if($video->thumbnail_path) poster="{{ route('videos.thumbnail', $video) }}" @endif src="{{ $sources[$initialQuality] }}" @loadedmetadata="restorePlayback()" @loadstart="loading = true" @loadeddata="loading = false" @canplay="loading = false" @playing="loading = false; failed = false" @waiting="loading = true" x-on:error="loading = false; failed = true">
        Dein Browser unterstützt diesen Videoplayer nicht.
    </video>
    <p class="video-loading" x-show="loading && !failed" x-cloak role="status">Video wird geladen…</p>
    <div class="video-playback-error" x-show="failed" x-cloak role="alert">
        <x-ui.icon name="warning" /><h2>Dieses Video konnte nicht abgespielt werden.</h2>
        <p>Die Verbindung wurde möglicherweise unterbrochen oder dein Browser unterstützt dieses Original nicht. Versuche es erneut oder bitte die Person, die eure Mediathek verwaltet, um Hilfe.</p>
        <button class="hr-button hr-button-secondary" type="button" @click="retry()">Erneut versuchen</button>
    </div>
</section>

@if($hasOptimized)
    <div class="video-quality" x-cloak>
        <div class="video-quality-options" role="group" aria-label="Wiedergabequalität">
            <button type="button" :aria-pressed="quality === 'optimized'" @click="switchQuality('optimized')">Optimiert <span>{{ $video->optimized_height }}p</span></button>
            <button type="button" :aria-pressed="quality === 'original'" @click="switchQuality('original')">Original @if($video->height)<span>{{ $video->height }}p</span>@endif</button>
        </div>
        <p>Die optimierte Version benötigt meist weniger Bandbreite. Das Original bietet die hochgeladene Qualität.</p>
    </div>
@endif
<p class="sr-only" role="status" x-text="playbackMessage"></p>
</div>
