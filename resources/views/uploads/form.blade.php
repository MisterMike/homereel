@php
$options = ['account' => auth()->id(), 'csrf' => csrf_token(), 'createUrl' => route('uploads.create'), 'maxBytes' => config('video.max_bytes')];
@endphp
<section class="upload-panel" @if($filament ?? false) x-load x-load-src="{{ Vite::asset('resources/js/components/resumable-upload.js') }}" @endif x-data="resumableUpload({{ Js::from($options) }})">
    <h2>Keep the moments that matter.</h2>
    <p>Upload MP4, MOV, WebM or MKV, up to {{ number_format(config('video.max_bytes') / 1073741824, 0) }} GiB. For reliable playback, use an MP4 with H.264 video and AAC audio.</p>
    <label class="file-picker">Choose a video
        <input x-ref="file" type="file" accept=".mp4,.m4v,.mov,.webm,.mkv" @change="selectFile($event)" :disabled="busy || !!completedUrl">
    </label>
    <p class="file-details" x-text="fileLabel"></p>
    <label for="upload-title">Video title</label>
    <input class="text-input" id="upload-title" type="text" maxlength="255" x-model="title" :disabled="busy || !!session" required>
    <div class="upload-progress" x-show="session" x-cloak>
        <progress max="100" :value="percentage" :aria-label="'Upload ' + percentage + '% complete'"></progress>
        <p><strong x-text="percentage + '%'"></strong> confirmed <span x-show="session" x-text="session ? formatSize(session.offset) + ' / ' + formatSize(session.size) : ''"></span></p>
    </div>
    <p role="status" aria-live="polite" x-text="message"></p>
    <p class="form-error" role="alert" x-show="error" x-text="error" x-cloak></p>
    <div class="upload-actions">
        <button type="button" class="button" @click="start()" :disabled="!file || busy || !title.trim() || !!completedUrl" x-text="completedUrl ? 'Upload complete' : (session ? 'Resume upload' : 'Start upload')">Start upload</button>
        <button type="button" class="button button-secondary" @click="pause()" :disabled="!busy || paused">Pause</button>
        <button type="button" class="text-button" @click="reset()" :disabled="busy">Start a different upload</button>
    </div>
    <a class="button" x-show="completedUrl" :href="completedUrl" x-cloak>Open video</a>
    <p class="upload-note">Keep this tab open while uploading. After a reload, select the same file to resume. Incomplete uploads expire after {{ config('video.upload_hours') }} hours.</p>
</section>
