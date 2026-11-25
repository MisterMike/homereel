<x-layout :title="$video->title">
    <a class="hr-back-link" href="{{ route('videos.index') }}"><x-ui.icon name="back" /> Zurück zur Mediathek</a>
    @if($video->status === \App\Enums\VideoStatus::Ready && $video->hasPlaybackCopy())
        <x-video.player :video="$video" />
    @else
        <section class="video-processing" role="status" aria-labelledby="video-status-heading">
            <x-ui.icon :name="$video->status === \App\Enums\VideoStatus::Failed ? 'warning' : 'film'" />
            <h2 id="video-status-heading">{{ $video->status === \App\Enums\VideoStatus::Failed || $video->web_status === 'failed' ? 'Verarbeitung fehlgeschlagen' : ($video->status === \App\Enums\VideoStatus::Ready && $video->web_status === 'not_requested' ? 'Web-Version noch nicht verfügbar' : 'Dein Video wird vorbereitet') }}</h2>
            <p>{{ $video->processing_error ?? $video->web_error ?? ($video->status === \App\Enums\VideoStatus::Ready && $video->web_status === 'not_requested' ? 'Das Original ist sicher gespeichert. Bitte die hochladende Person oder einen Administrator, die Web-Version auf der Bearbeitungsseite zu erstellen.' : 'Das Original ist sicher gespeichert. Die hochladende Person erhält eine E-Mail, sobald das Video abspielbar ist.') }}</p>
            <a class="hr-button hr-button-secondary" href="{{ route('videos.show', $video) }}">Erneut prüfen</a>
        </section>
    @endif
    <div class="video-detail-heading">
        <div><h1>{{ $video->title }}</h1><x-video.metadata :video="$video" :duration="true" /></div>
        <span class="hr-private-label"><x-ui.icon name="lock" /> Privates Video</span>
    </div>
    @if($video->description)<div class="video-description"><p>{{ $video->description }}</p></div>@endif
</x-layout>
