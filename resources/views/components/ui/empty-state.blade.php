<section class="hr-empty-state">
    <div class="hr-empty-symbol"><x-ui.icon name="film" /></div>
    <h2>Ein Zuhause für eure gemeinsamen Geschichten.</h2>
    <p>{{ auth()->user()->canUploadVideos() ? 'Deine Mediathek wartet auf das erste Video. Beginne mit einem Moment, den du gerne wiedersehen möchtest.' : 'Noch sind keine Videos verfügbar. Sobald ein Video für deine Familie freigegeben wird, findest du es hier.' }}</p>
    @if(auth()->user()->canUploadVideos())<a class="hr-button" href="{{ route('filament.admin.pages.upload-video') }}"><x-ui.icon name="upload" /> Lade dein erstes Video hoch</a>@endif
    <span class="hr-empty-privacy"><x-ui.icon name="lock" /> Nur für deine Familie verfügbar</span>
</section>
