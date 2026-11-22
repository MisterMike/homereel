@php
    $options = ['account' => auth()->id(), 'csrf' => csrf_token(), 'createUrl' => route('uploads.create'), 'maxBytes' => config('video.max_bytes')];
@endphp
<section class="upload-panel" @if($filament ?? false) x-load x-load-src="{{ Vite::asset('resources/js/components/resumable-upload.js') }}" @endif x-data="resumableUpload({{ Js::from($options) }})" aria-labelledby="upload-heading">
    <a class="upload-back" href="{{ route('videos.index') }}"><x-ui.icon name="back" /> Back to your videos</a>
    <div class="upload-heading">
        <h2 id="upload-heading">Keep the moments that matter.</h2>
        <p>Add a video to your family’s library. You can pause and pick up where you left off.</p>
    </div>
    <label class="upload-file-picker">
        <x-ui.icon name="upload" />
        <span class="upload-select-title">Choose a video</span>
        <span class="upload-select-hint">Select a file from your device</span>
        <input x-ref="file" type="file" accept=".mp4,.m4v,.mov,.webm,.mkv" @change="selectFile($event)" :disabled="busy || !!completedUrl">
    </label>
    <p class="upload-formats">MP4, MOV, WebM or MKV · Up to {{ number_format(config('video.max_bytes') / 1073741824, 0) }} GiB</p>
    <div class="upload-file-info"><x-ui.icon name="film" /><p x-text="fileLabel">No file selected</p></div>
    <label class="upload-title-label" for="upload-title">Video title</label>
    <input class="hr-input" id="upload-title" type="text" maxlength="255" x-model="title" :disabled="busy || !!session" required>
    <div class="upload-progress" x-show="session" x-cloak>
        <div class="upload-progress-heading">
            <span x-text="completedUrl ? 'Upload received' : (busy && !paused ? 'Uploading' : 'Paused')"></span>
            <strong x-text="percentage + '%'" aria-live="polite" aria-atomic="true"></strong>
        </div>
        <progress max="100" :value="percentage" :aria-label="'Upload ' + percentage + '% complete'"></progress>
        <p><span x-text="session ? formatSize(session.offset) + ' / ' + formatSize(session.size) : ''"></span> confirmed by the server</p>
    </div>
    <p class="upload-message" role="status" aria-live="polite" x-text="message"></p>
    <p class="hr-form-error" role="alert" x-show="error" x-text="error" x-cloak></p>
    <div class="upload-actions">
        <button type="button" class="hr-button" @click="start()" :disabled="!file || busy || !title.trim() || !!completedUrl" x-text="completedUrl ? 'Upload complete' : (session ? 'Resume upload' : 'Start upload')">Start upload</button>
        <button type="button" class="hr-button hr-button-secondary" @click="pause()" :disabled="!busy || paused">Pause</button>
        <button type="button" class="hr-text-button" @click="reset()" :disabled="busy">Start a different upload</button>
    </div>
    <a class="hr-button upload-open" x-show="completedUrl" :href="completedUrl" x-cloak><x-ui.icon name="play" /> Open video</a>
    <div class="upload-guidance">
        <x-ui.icon name="lock" />
        <div><p>Your original stays private. You choose when it becomes available to family members.</p><p>Keep this tab open while uploading. After a reload, select the same file to resume. Incomplete uploads expire after {{ config('video.upload_hours') }} hours.</p><p>For reliable playback, use MP4 with H.264 video and AAC audio.</p></div>
    </div>
</section>
