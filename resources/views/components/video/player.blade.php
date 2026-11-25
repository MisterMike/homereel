@props(['video'])
@php
    $hasOptimized = $video->hasOptimizedCopy();
    $hasStandard = $video->hasWebCopy();
    $initialQuality = $hasStandard ? 'standard' : 'hd';
    $sources = [];
    if ($hasStandard) {
        $sources['standard'] = route('videos.stream', ['video' => $video, 'quality' => 'standard']);
    }
    if ($hasOptimized) {
        $sources['hd'] = route('videos.stream', ['video' => $video, 'quality' => 'hd']);
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
        <p>Die Verbindung wurde möglicherweise unterbrochen oder dein Browser konnte die Web-Version nicht laden. Versuche es erneut oder bitte die Person, die eure Mediathek verwaltet, um Hilfe.</p>
        <button class="hr-button hr-button-secondary" type="button" @click="retry()">Erneut versuchen</button>
    </div>
</section>

<div class="video-quality">
    @if($hasStandard && $hasOptimized)
        <div class="video-quality-options" role="group" aria-label="Wiedergabequalität">
            <button type="button" :aria-pressed="quality === 'standard'" @click="switchQuality('standard')">Standard <span>{{ $video->web_height }}p</span></button>
            <button type="button" :aria-pressed="quality === 'hd'" @click="switchQuality('hd')">Höhere Qualität <span>{{ $video->optimized_height }}p</span></button>
        </div>
        <p>Standard benötigt weniger Bandbreite. Bei einer langsamen Verbindung wähle diese Version.</p>
    @elseif($hasStandard)
        <p>Standard · {{ $video->web_height }}p · Für eine flüssige Wiedergabe bei geringer Bandbreite.</p>
    @else
        <p>Web-Version · {{ $video->optimized_height }}p</p>
    @endif
    @if(in_array($video->optimization_status, ['queued', 'processing'], true))
        <p role="status">HD wird vorbereitet. Du kannst das Video bereits in Standardqualität ansehen. Lade die Seite später neu, um HD auszuwählen.</p>
    @elseif($hasStandard && $video->optimization_status === 'failed')
        <p role="status">HD konnte nicht erstellt werden. Die Standardversion bleibt abspielbar.</p>
    @endif
</div>
<div class="video-quality" x-show="offerFullscreen" x-cloak>
    <button type="button" class="hr-button hr-button-secondary" @click="enterFullscreen()"><x-ui.icon name="fullscreen" /> Vollbild</button>
    <p x-show="fullscreenNeedsTap" role="status">Tippe auf «Vollbild», um das Video bildschirmfüllend anzusehen.</p>
</div>
<p class="sr-only" role="status" x-text="playbackMessage"></p>
</div>
