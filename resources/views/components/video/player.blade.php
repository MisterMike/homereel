@props(['video'])
@php
    $hasOptimized = $video->hasOptimizedCopy();
    $initialQuality = $hasOptimized ? 'optimized' : 'original';
    $sources = ['original' => route('videos.stream', ['video' => $video, 'quality' => 'original'])];
    if ($hasOptimized) {
        $sources['optimized'] = route('videos.stream', ['video' => $video, 'quality' => 'optimized']);
    }
    $playerOptions = ['sources' => $sources, 'initialQuality' => $initialQuality, 'videoId' => $video->uuid, 'viewUrl' => route('videos.views.store', $video), 'csrf' => csrf_token()];
@endphp
<div x-data="videoPlayer({{ Js::from($playerOptions) }})">
<section class="video-stage" :class="{ 'has-playback-error': failed }" aria-label="Videoplayer">
    <video x-ref="video" class="video-player" controls playsinline preload="metadata" aria-label="{{ $video->title }}" @if($video->thumbnail_path) poster="{{ route('videos.thumbnail', $video) }}" @endif src="{{ $sources[$initialQuality] }}" @loadedmetadata="restorePlayback()" @loadstart="loading = true" @loadeddata="loading = false" @canplay="loading = false" @play="loading = $refs.video.readyState < 3; handleOrientationChange()" @pause="loading = false" @playing="loading = false; failed = false; recordView(); handleOrientationChange()" @waiting="loading = !$refs.video.paused" x-on:error="loading = false; failed = true">
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
<div class="video-quality" x-show="offerFullscreen" x-cloak>
    <button type="button" class="hr-button hr-button-secondary" @click="enterFullscreen()"><x-ui.icon name="fullscreen" /> Vollbild</button>
    <p x-show="fullscreenNeedsTap" role="status">Tippe auf «Vollbild», um das Video bildschirmfüllend anzusehen.</p>
</div>
<p class="sr-only" role="status" x-text="playbackMessage"></p>
</div>
