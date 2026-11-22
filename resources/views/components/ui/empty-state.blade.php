<section class="hr-empty-state">
    <div class="hr-empty-symbol"><x-ui.icon name="film" /></div>
    <h2>A home for your shared stories.</h2>
    <p>{{ auth()->user()->is_admin ? 'Your library is ready for its first video. Start with a moment you would love to see again.' : 'There are no videos available yet. When your family administrator shares a video, you’ll find it here.' }}</p>
    @if(auth()->user()->is_admin)<a class="hr-button" href="{{ route('filament.admin.pages.upload-video') }}"><x-ui.icon name="upload" /> Upload your first video</a>@endif
    <span class="hr-empty-privacy"><x-ui.icon name="lock" /> Only available to your family</span>
</section>
