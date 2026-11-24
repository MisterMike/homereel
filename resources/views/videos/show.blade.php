<x-layout :title="$video->title">
    <a class="hr-back-link" href="{{ route('videos.index') }}"><x-ui.icon name="back" /> Zurück zur Mediathek</a>
    @if($video->status === \App\Enums\VideoStatus::Ready)
        <x-video.player :video="$video" />
    @else
        <section class="video-processing" role="status" aria-labelledby="video-status-heading">
            <x-ui.icon :name="$video->status === \App\Enums\VideoStatus::Failed ? 'warning' : 'film'" />
            <h2 id="video-status-heading">{{ $video->status === \App\Enums\VideoStatus::Failed ? 'Videoprüfung fehlgeschlagen' : 'Dein Video wird vorbereitet' }}</h2>
            <p>{{ $video->processing_error ?? 'Dein Original ist sicher gespeichert. Sobald die Prüfung abgeschlossen ist, kannst du es hier abspielen.' }}</p>
            <a class="hr-button hr-button-secondary" href="{{ route('videos.show', $video) }}">Erneut prüfen</a>
        </section>
    @endif
    <div class="video-detail-heading">
        <div><h1>{{ $video->title }}</h1><x-video.metadata :video="$video" :duration="true" /></div>
        <span class="hr-private-label"><x-ui.icon name="lock" /> Privates Video</span>
    </div>
    @if($video->status === \App\Enums\VideoStatus::Ready && !$video->browser_compatible && !$video->hasOptimizedCopy())
        <p class="hr-notice"><x-ui.icon name="warning" /> Dieses Original lässt sich möglicherweise nicht in jedem Browser abspielen. Eine optimierte MP4-Kopie kann helfen. Bitte die Person, die eure Mediathek verwaltet, eine Kopie zu erstellen.</p>
    @endif
    @if($video->description)<div class="video-description"><p>{{ $video->description }}</p></div>@endif
</x-layout>
