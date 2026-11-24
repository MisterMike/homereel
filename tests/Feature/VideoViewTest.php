<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class VideoViewTest extends TestCase
{
    use RefreshDatabase;

    private User $viewer;

    private Video $video;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('videos');
        $this->viewer = User::factory()->create();
        $this->actingAs($this->viewer);
        $this->withSession([])->withCredentials()->withCookie(config('session.cookie'), session()->getId());
        $this->video = Video::create([
            'uuid' => (string) Str::uuid(), 'title' => 'A family moment',
            'original_filename' => 'test.mp4', 'mime_type' => 'video/mp4', 'file_size' => 10,
            'status' => VideoStatus::Ready, 'storage_path' => 'originals/test',
            'storage_provider' => 'local', 'uploaded_by' => $this->viewer->id,
            'prototype_available' => true,
        ]);
        Storage::disk('videos')->put($this->video->storage_path, '0123456789');
    }

    public function test_opening_pages_and_fetching_video_data_do_not_count_as_views(): void
    {
        $this->get(route('videos.index'))->assertOk()->assertSee('0 Aufrufe');
        $this->get(route('videos.show', $this->video))->assertOk()->assertSee('0 Aufrufe');
        $this->head(route('videos.stream', $this->video))->assertOk();
        $this->withHeader('Range', 'bytes=0-4')->get(route('videos.stream', $this->video))->assertStatus(206);

        $this->assertSame(0, $this->video->fresh()->view_count);
        $this->assertDatabaseCount('video_views', 0);
    }

    public function test_playback_is_counted_once_per_video_per_session(): void
    {
        $url = route('videos.views.store', $this->video);
        $this->postJson($url)->assertOk()->assertJsonPath('view_count', 1);
        $this->postJson($url)->assertOk()->assertJsonPath('view_count', 1);
        $this->get(route('videos.show', $this->video))->assertOk()->assertSee('1 Aufruf');
        $this->postJson($url)->assertOk()->assertJsonPath('view_count', 1);

        $this->assertSame(1, $this->video->fresh()->view_count);
        $this->assertDatabaseCount('video_views', 1);
        $this->assertDatabaseHas('video_views', [
            'video_id' => $this->video->id,
            'session_hash' => hash('sha256', $this->viewer->id.':'.session()->getId()),
        ]);

        session()->invalidate();
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->postJson($url)->assertOk()->assertJsonPath('view_count', 2);
        $this->assertDatabaseCount('video_views', 2);
    }

    public function test_each_video_and_each_authenticated_viewer_can_add_a_view(): void
    {
        $this->postJson(route('videos.views.store', $this->video))->assertOk()->assertJsonPath('view_count', 1);
        $anotherVideo = $this->video->replicate();
        $anotherVideo->uuid = (string) Str::uuid();
        $anotherVideo->save();
        $this->postJson(route('videos.views.store', $anotherVideo))->assertOk()->assertJsonPath('view_count', 1);

        $this->actingAs(User::factory()->create());
        $this->postJson(route('videos.views.store', $this->video))->assertOk()->assertJsonPath('view_count', 2);

        $this->assertSame(1, $anotherVideo->fresh()->view_count);
        $this->assertDatabaseCount('video_views', 3);
    }

    public function test_private_deleted_and_inactive_accounts_cannot_add_views(): void
    {
        $url = route('videos.views.store', $this->video);
        $this->video->update(['prototype_available' => false]);
        $this->postJson($url)->assertForbidden();
        $this->video->update(['prototype_available' => true]);

        $this->viewer->forceFill(['is_active' => false])->save();
        $this->postJson($url)->assertForbidden();
        $this->viewer->forceFill(['is_active' => true])->save();
        $this->video->delete();
        $this->postJson($url)->assertNotFound();

        $this->assertDatabaseCount('video_views', 0);
        $this->assertSame(0, $this->video->fresh()->view_count);
    }

    public function test_unready_videos_cannot_add_views_even_for_administrators(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        foreach ([VideoStatus::Uploading, VideoStatus::Processing, VideoStatus::Failed] as $status) {
            $this->video->update(['status' => $status]);
            $this->postJson(route('videos.views.store', $this->video))->assertConflict();
        }

        $this->assertSame(0, $this->video->fresh()->view_count);
        $this->assertDatabaseCount('video_views', 0);
    }

    public function test_guests_cannot_add_views(): void
    {
        auth()->forgetGuards();
        $this->postJson(route('videos.views.store', $this->video))->assertUnauthorized();

        $this->assertSame(0, $this->video->fresh()->view_count);
        $this->assertDatabaseCount('video_views', 0);
    }

    public function test_recording_a_view_requires_a_csrf_token(): void
    {
        $this->app['env'] = 'production';
        $this->postJson(route('videos.views.store', $this->video))->assertStatus(419);

        $this->assertSame(0, $this->video->fresh()->view_count);
        $this->assertDatabaseCount('video_views', 0);
    }
}
