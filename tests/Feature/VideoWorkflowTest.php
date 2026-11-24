<?php

namespace Tests\Feature;

use App\Enums\UploadStatus;
use App\Enums\VideoStatus;
use App\Filament\Resources\Videos\Pages\EditVideo;
use App\Jobs\ProcessVideo;
use App\Models\UploadSession;
use App\Models\User;
use App\Models\Video;
use App\Services\Video\MediaLock;
use App\Services\Video\VideoInspector;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Tests\TestCase;

class VideoWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private mixed $realQueue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('videos');
        $this->realQueue = app('queue');
        Queue::fake();
        config(['video.chunk_bytes' => 8192, 'video.thumbnails' => false, 'queue.default' => 'database']);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true])->save();

        return $user;
    }

    private function video(array $attributes = []): Video
    {
        return Video::create(array_merge(['uuid' => (string) Str::uuid(), 'title' => 'Family day', 'original_filename' => 'day.mp4', 'mime_type' => 'video/mp4', 'file_size' => 10, 'status' => VideoStatus::Ready, 'storage_provider' => 'local', 'storage_path' => 'originals/test', 'uploaded_by' => $this->admin()->id], $attributes));
    }

    private function createSession(?int $size = null): array
    {
        $data = ['filename' => 'day.mp4', 'title' => 'Family day', 'size' => $size ?? strlen(file_get_contents(base_path('tests/Fixtures/sample.mp4'))), 'mime_type' => 'video/mp4'];

        return $this->postJson('/uploads', $data)->assertCreated()->json();
    }

    private function chunk(string $id, string $data, int $offset, ?string $hash = null)
    {
        return $this->call('PATCH', '/uploads/'.$id, [], [], [], [
            'CONTENT_TYPE' => 'application/octet-stream', 'CONTENT_LENGTH' => (string) strlen($data),
            'HTTP_ACCEPT' => 'application/json', 'HTTP_UPLOAD_OFFSET' => (string) $offset,
            'HTTP_UPLOAD_CHECKSUM' => $hash ?? hash('sha256', $data),
        ], $data);
    }

    public function test_guests_cannot_access_pages_streams_thumbnails_or_uploads(): void
    {
        $video = $this->video(['thumbnail_path' => 'thumb.jpg']);
        foreach (['/', '/videos/'.$video->uuid, '/videos/'.$video->uuid.'/stream', '/videos/'.$video->uuid.'/thumbnail', '/admin/videos', '/admin/upload-video'] as $url) {
            $this->get($url)->assertRedirect();
        }
        $this->postJson('/uploads', [])->assertUnauthorized();
    }

    public function test_login_logout_and_rate_limiting(): void
    {
        $user = User::factory()->create(['password' => 'correct-password']);
        $this->post('/login', ['email' => $user->email, 'password' => 'correct-password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        for ($n = 0; $n < 6; $n++) {
            $response = $this->postJson('/login', ['email' => $user->email, 'password' => 'wrong']);
        }
        $response->assertUnprocessable()->assertSee('Zu viele Versuche');
        $this->get('/register')->assertNotFound();
    }

    public function test_members_cannot_manage_videos_or_upload_and_only_see_available_videos(): void
    {
        $private = $this->video(['thumbnail_path' => 'private-preview.jpg']);
        $available = $this->video(['prototype_available' => true, 'title' => 'Shared day', 'thumbnail_path' => 'shared-preview.jpg']);
        $this->actingAs(User::factory()->create());
        foreach (['/admin', '/admin/videos', '/admin/upload-video'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->postJson('/uploads')->assertForbidden();
        $this->get('/')->assertOk()->assertSee('Shared day')->assertDontSee('Family day')
            ->assertSee('Ein Moment zum Wiedererleben')
            ->assertSee(route('videos.thumbnail', $available), false)
            ->assertDontSee(route('videos.thumbnail', $private), false)
            ->assertDontSee(route('filament.admin.pages.upload-video'), false);
        foreach (['', '/stream', '/thumbnail'] as $suffix) {
            $this->get('/videos/'.$private->uuid.$suffix)->assertForbidden();
        }
        $this->get('/videos/'.$available->uuid)->assertOk();
    }

    public function test_admin_can_access_panel_and_edit_video_without_exposing_storage_paths(): void
    {
        $video = $this->video();
        $this->actingAs($this->admin());
        $this->get('/admin')->assertOk();
        $this->get('/admin/videos')->assertOk();
        $this->get('/admin/videos/'.$video->uuid.'/edit')->assertOk();
        $this->get('/admin/upload-video')->assertOk();
        $this->get('/videos/'.$video->uuid)->assertOk()->assertDontSee($video->storage_path);
    }

    public function test_upload_creation_large_integer_limits_permissions_and_metadata(): void
    {
        $owner = $this->admin();
        $this->actingAs($owner);
        $session = $this->createSession(5 * 1073741824);
        $this->assertSame(5 * 1073741824, $session['size']);
        $this->assertSame(0, $session['offset']);
        $this->assertArrayNotHasKey('temporary_path', $session);
        $this->actingAs($this->admin());
        $this->getJson('/uploads/'.$session['id'])->assertForbidden();
        $this->chunk($session['id'], 'abc', 0)->assertForbidden();
        $this->postJson('/uploads/'.$session['id'].'/finish')->assertForbidden();
        $this->actingAs($owner);
        $this->postJson('/uploads', ['filename' => '../bad.mp4', 'title' => 'bad', 'size' => 10, 'mime_type' => 'video/mp4'])->assertUnprocessable();
        $this->postJson('/uploads', ['filename' => 'bad.exe', 'title' => 'bad', 'size' => 10, 'mime_type' => 'application/octet-stream'])->assertUnprocessable();
        $this->postJson('/uploads', ['filename' => 'big.mp4', 'title' => 'big', 'size' => config('video.max_bytes') + 1, 'mime_type' => 'video/mp4'])->assertUnprocessable();
        config(['video.storage_limit_bytes' => 5 * 1073741824]);
        $this->sessionWouldExceedQuota();
    }

    private function sessionWouldExceedQuota(): void
    {
        $this->postJson('/uploads', ['filename' => 'day.mp4', 'title' => 'Day', 'size' => 1, 'mime_type' => 'video/mp4'])->assertUnprocessable();
    }

    public function test_sequential_chunks_resume_retry_checksum_and_final_integrity(): void
    {
        $this->actingAs($this->admin());
        $session = $this->createSession();
        $id = $session['id'];
        $bytes = file_get_contents(base_path('tests/Fixtures/sample.mp4'));
        $first = substr($bytes, 0, 8192);
        $this->chunk($id, $first, 0)->assertOk()->assertJsonPath('offset', 8192);
        $this->getJson('/uploads/'.$id)->assertOk()->assertJsonPath('offset', 8192);
        $this->chunk($id, $first, 0)->assertConflict();
        $next = substr($bytes, 8192, 8192);
        $this->chunk($id, $next, 8192, str_repeat('0', 64))->assertUnprocessable();
        $this->assertSame(8192, UploadSession::find($id)->offset);
        $this->assertSame($first, Storage::disk('videos')->get('uploads/'.$id.'.part'));
        for ($offset = 8192; $offset < strlen($bytes); $offset += 8192) {
            $this->chunk($id, substr($bytes, $offset, 8192), $offset)->assertOk();
        }
        $this->postJson('/uploads/'.$id.'/finish')->assertOk();
        $video = Video::firstOrFail();
        $this->assertSame($bytes, Storage::disk('videos')->get($video->storage_path));
        $this->assertSame(VideoStatus::Processing, $video->status);
        $this->assertFalse($video->prototype_available);
        Queue::assertPushed(ProcessVideo::class, 1);
        $this->postJson('/uploads/'.$id.'/finish')->assertOk();
        $this->assertSame(1, Video::count());
        Queue::assertPushed(ProcessVideo::class, 1);
    }

    public function test_concurrent_lock_rejects_second_writer_and_crash_tail_is_repaired(): void
    {
        $this->actingAs($this->admin());
        $session = $this->createSession(20);
        $id = $session['id'];
        $lock = app(MediaLock::class);
        $lock->run($id, function () use ($id) {
            $this->chunk($id, 'abc', 0)->assertConflict();
        });
        Storage::disk('videos')->put('uploads/'.$id.'.part', 'uncommitted tail');
        $this->chunk($id, 'abc', 0)->assertOk()->assertJsonPath('offset', 3);
        $this->assertSame('abc', Storage::disk('videos')->get('uploads/'.$id.'.part'));
        $this->chunk($id, 'abc', 2)->assertConflict();
        $this->postJson('/uploads/'.$id.'/finish')->assertConflict();
    }

    public function test_corrupt_or_oversized_chunks_and_unsupported_content_are_rejected(): void
    {
        $this->actingAs($this->admin());
        $id = $this->createSession(13)['id'];
        $this->chunk($id, '<html>oops!!', 0)->assertOk();
        $this->chunk($id, 'too long', 12)->assertUnprocessable();
        $this->chunk($id, '!', 12)->assertOk();
        $this->postJson('/uploads/'.$id.'/finish')->assertUnprocessable();
        Queue::assertNothingPushed();
        $this->assertSame(0, Video::count());
        $id2 = $this->createSession(20000)['id'];
        $this->chunk($id2, str_repeat('x', 8193), 0)->assertUnprocessable();
    }

    public function test_expired_upload_cleanup_preserves_originals(): void
    {
        $this->actingAs($this->admin());
        $id = $this->createSession(20)['id'];
        $this->chunk($id, 'abc', 0)->assertOk();
        UploadSession::find($id)->update(['expires_at' => now()->subMinute()]);
        $this->chunk($id, 'def', 3)->assertGone();
        Storage::disk('videos')->put('originals/keep', 'original');
        $this->artisan('uploads:cleanup')->assertSuccessful();
        Storage::disk('videos')->assertMissing('uploads/'.$id.'.part');
        Storage::disk('videos')->assertExists('originals/keep');
        $this->assertSame(UploadStatus::Expired, UploadSession::find($id)->status);
    }

    public function test_real_probe_thumbnail_and_failed_inspection_retain_original(): void
    {
        config(['video.thumbnails' => true]);
        $video = $this->video(['status' => VideoStatus::Processing]);
        Storage::disk('videos')->put($video->storage_path, file_get_contents(base_path('tests/Fixtures/sample.mp4')));
        (new ProcessVideo($video->id))->handle(app(VideoInspector::class), app(MediaLock::class));
        $video->refresh();
        $this->assertSame(VideoStatus::Ready, $video->status);
        $this->assertSame(320, $video->width);
        $this->assertSame('h264', $video->video_codec);
        $this->assertSame('aac', $video->audio_codec);
        $this->assertTrue($video->browser_compatible);
        $this->assertGreaterThan(1, $video->duration_seconds);
        Storage::disk('videos')->assertExists($video->thumbnail_path);
        $video->update(['status' => VideoStatus::Processing]);
        Storage::disk('videos')->put($video->storage_path, 'not video');
        try {
            (new ProcessVideo($video->id))->handle(app(VideoInspector::class), app(MediaLock::class));
            $this->fail('Invalid video accepted');
        } catch (ProcessFailedException $e) {
            $this->assertSame(VideoStatus::Failed, $video->fresh()->status);
        }
        Storage::disk('videos')->assertExists($video->storage_path);
    }

    public function test_soft_deletion_blocks_access_and_jobs_but_retains_original_until_explicit_purge(): void
    {
        $video = $this->video();
        Storage::disk('videos')->put($video->storage_path, 'original');
        $video->delete();
        $this->actingAs($this->admin())->get('/videos/'.$video->uuid.'/stream')->assertNotFound();
        (new ProcessVideo($video->id))->handle(app(VideoInspector::class), app(MediaLock::class));
        Storage::disk('videos')->assertExists($video->storage_path);
        $this->artisan('videos:purge')->assertFailed();
        $video->forceFill(['deleted_at' => now()->subDays(31)])->save();
        $this->artisan('videos:purge', ['--force' => true])->assertSuccessful();
        Storage::disk('videos')->assertMissing($video->storage_path);
    }

    public function test_finalization_enqueues_on_database_and_recovers_after_database_failure(): void
    {
        Queue::swap($this->realQueue);
        $this->actingAs($this->admin());
        $bytes = file_get_contents(base_path('tests/Fixtures/sample.mp4'));
        $id = $this->createSession()['id'];
        for ($offset = 0; $offset < strlen($bytes); $offset += 8192) {
            $this->chunk($id, substr($bytes, $offset, 8192), $offset)->assertOk();
        }
        $event = 'eloquent.created: '.Video::class;
        Event::listen($event, function () {
            throw new \RuntimeException('Simulated transaction failure');
        });
        $this->postJson('/uploads/'.$id.'/finish')->assertStatus(500);
        Event::forget($event);
        $this->assertDatabaseCount('videos', 0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame(UploadStatus::Active, UploadSession::find($id)->status);
        Storage::disk('videos')->assertExists('originals/'.$id);
        $this->postJson('/uploads/'.$id.'/finish')->assertOk();
        $this->assertDatabaseCount('videos', 1);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame($bytes, Storage::disk('videos')->get('originals/'.$id));
    }

    public function test_browser_posts_require_csrf_tokens(): void
    {
        $this->app['env'] = 'production';
        $this->post('/login', ['email' => 'user@example.test', 'password' => 'incorrect'])->assertStatus(419);
        $this->actingAs($this->admin());
        $this->postJson('/uploads', [])->assertStatus(419);
    }

    public function test_administrator_can_edit_share_and_soft_delete_through_filament(): void
    {
        $video = $this->video();
        Storage::disk('videos')->put($video->storage_path, 'original');
        $this->actingAs($this->admin());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::test(EditVideo::class, ['record' => $video->uuid])
            ->fillForm(['title' => 'Updated family video', 'description' => 'A family note.', 'prototype_available' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('videos', ['id' => $video->id, 'title' => 'Updated family video', 'description' => 'A family note.', 'prototype_available' => true]);
        $component->callAction('delete');
        $this->assertSoftDeleted($video);
        Storage::disk('videos')->assertExists($video->storage_path);
    }

    public function test_video_title_and_description_escape_html_in_gallery_and_player(): void
    {
        $script = '<script>alert(1)</script>';
        $video = $this->video(['title' => $script, 'description' => $script]);
        $this->actingAs($this->admin());

        $this->get('/')->assertOk()->assertSee($script)->assertDontSee($script, false);
        $this->get('/videos/'.$video->uuid)->assertOk()->assertSee($script)->assertDontSee($script, false);
    }

    public function test_fast_upload_accepts_all_512_chunks_without_hitting_the_control_request_limit(): void
    {
        $this->actingAs($this->admin());
        $this->freezeTime();
        $id = $this->createSession(512)['id'];

        for ($offset = 0; $offset < 512; $offset++) {
            $this->chunk($id, 'x', $offset)->assertOk()->assertJsonPath('offset', $offset + 1);
        }

        $this->getJson('/uploads/'.$id)->assertOk()->assertJsonPath('offset', 512);
        $this->assertSame(str_repeat('x', 512), Storage::disk('videos')->get('uploads/'.$id.'.part'));
    }

    public function test_chunk_throttling_preserves_offset_and_allows_status_and_resume_after_retry_delay(): void
    {
        config(['video.chunk_requests_per_minute' => 2]);
        $this->actingAs($this->admin());
        $this->freezeTime();
        $id = $this->createSession(3)['id'];
        $this->chunk($id, 'a', 0)->assertOk();
        $this->chunk($id, 'b', 1)->assertOk();

        $response = $this->chunk($id, 'c', 2)->assertTooManyRequests()->assertHeader('Retry-After');
        $this->getJson('/uploads/'.$id)->assertOk()->assertJsonPath('offset', 2);
        $this->assertSame('ab', Storage::disk('videos')->get('uploads/'.$id.'.part'));

        $this->travel((int) $response->headers->get('Retry-After') + 1)->seconds();
        $this->chunk($id, 'c', 2)->assertOk()->assertJsonPath('offset', 3);
        $this->assertSame('abc', Storage::disk('videos')->get('uploads/'.$id.'.part'));
    }
}
