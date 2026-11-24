@php
    $options = ['account' => auth()->id(), 'csrf' => csrf_token(), 'createUrl' => route('uploads.create'), 'maxBytes' => config('video.max_bytes')];
@endphp
<section class="upload-panel" @if($filament ?? false) x-load x-load-src="{{ Vite::asset('resources/js/components/resumable-upload.js') }}" @endif x-data="resumableUpload({{ Js::from($options) }})" aria-labelledby="upload-heading">
    <a class="upload-back" href="{{ route('videos.index') }}"><x-ui.icon name="back" /> Zurück zu deinen Videos</a>
    <div class="upload-heading">
        <h2 id="upload-heading">Bewahre die Momente, die zählen.</h2>
        <p>Füge ein Video zur Mediathek deiner Familie hinzu. Du kannst den Upload pausieren und später fortsetzen.</p>
    </div>
    <div x-show="!completedUrl">
        <label class="upload-file-picker">
            <x-ui.icon name="upload" />
            <span class="upload-select-title">Video auswählen</span>
            <span class="upload-select-hint">Wähle eine Datei auf deinem Gerät</span>
            <input x-ref="file" type="file" accept=".mp4,.m4v,.mov,.webm,.mkv" @change="selectFile($event)" :disabled="busy || !!completedUrl">
        </label>
        <p class="upload-formats">MP4, MOV, WebM oder MKV · Bis zu {{ number_format(config('video.max_bytes') / 1073741824, 0) }} GiB</p>
        <div class="upload-file-info"><x-ui.icon name="film" /><p x-text="fileLabel">Keine Datei ausgewählt</p></div>
        <label class="upload-title-label" for="upload-title">Videotitel</label>
        <input class="hr-input" id="upload-title" type="text" maxlength="255" x-model="title" :disabled="busy || !!session" required>
    </div>
    <div class="upload-progress" x-show="session" x-cloak>
        <div class="upload-progress-heading">
            <span x-text="completedUrl ? 'Upload empfangen' : (busy && !paused ? 'Wird hochgeladen' : 'Pausiert')"></span>
            <strong x-text="percentage + '%'" aria-live="polite" aria-atomic="true"></strong>
        </div>
        <progress max="100" :value="percentage" :aria-label="'Upload ' + percentage + '% abgeschlossen'"></progress>
        <p><span x-text="session ? formatSize(session.offset) + ' / ' + formatSize(session.size) : ''"></span> vom Server bestätigt</p>
    </div>
    <p class="upload-message" role="status" aria-live="polite" x-text="message"></p>
    <p class="hr-form-error" role="alert" x-show="error" x-text="error" x-cloak></p>
    <div class="upload-actions" x-show="!completedUrl">
        <button type="button" class="hr-button" @click="start()" :disabled="!file || busy || !title.trim() || !!completedUrl" x-text="completedUrl ? 'Upload abgeschlossen' : (session ? 'Upload fortsetzen' : 'Upload starten')">Upload starten</button>
        <button type="button" class="hr-button hr-button-secondary" @click="pause()" :disabled="!busy || paused">Pause</button>
        <button type="button" class="hr-text-button" @click="reset()" :disabled="busy">Neuen Upload starten</button>
    </div>
    <section class="upload-complete" x-show="completedUrl" x-cloak aria-labelledby="upload-complete-heading">
        <h2 id="upload-complete-heading" tabindex="-1" x-ref="completeHeading">Dein Video wurde hochgeladen.</h2>
        <p>Die Verarbeitung läuft im Hintergrund weiter. Unter «Videos verwalten» kannst du ein Album wählen und das fertige Video für deine Familie freigeben.</p>
        <div class="upload-actions">
            <a class="hr-button" href="{{ route('filament.admin.resources.videos.index') }}">Videos verwalten</a>
            <a class="hr-button hr-button-secondary" href="{{ route('videos.index') }}">Alle Videos anzeigen</a>
            <button class="hr-text-button" type="button" @click="reset()">Weiteres Video hochladen</button>
        </div>
    </section>
    <div class="upload-guidance">
        <x-ui.icon name="lock" />
        <div><p>Dein Original bleibt privat. Du entscheidest, wann es für deine Familie verfügbar ist.</p><p>Lass diesen Tab während des Uploads geöffnet. Wähle nach dem Neuladen dieselbe Datei, um fortzufahren. Unvollständige Uploads verfallen nach {{ config('video.upload_hours') }} Stunden.</p><p>Für eine zuverlässige Wiedergabe verwende MP4 mit H.264-Video und AAC-Audio.</p></div>
    </div>
</section>
