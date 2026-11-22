<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Filament\Resources\Videos\Pages\EditVideo;
use App\Jobs\GenerateVideoThumbnail;
use App\Models\User;
use App\Models\Video;
use App\Services\Video\MediaLock;
use App\Services\Video\VideoInspector;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ThumbnailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('videos');
        Queue::fake();
        config(['video.thumbnails' => true]);
    }

    private function video(array $attributes = []): Video
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $video = Video::create(array_merge([
            'uuid' => (string) Str::uuid(), 'title' => 'Thumbnail test', 'original_filename' => 'sample.mp4',
            'mime_type' => 'video/mp4', 'file_size' => 100, 'duration_seconds' => 2,
            'status' => VideoStatus::Ready, 'storage_provider' => 'local', 'storage_path' => 'originals/test',
            'uploaded_by' => $admin->id, 'thumbnail_path' => 'thumbnails/previous.jpg',
        ], $attributes));
        Storage::disk('videos')->put($video->storage_path, file_get_contents(base_path('tests/Fixtures/sample.mp4')));
        Storage::disk('videos')->put('thumbnails/previous.jpg', 'previous preview');

        return $video;
    }

    public function test_saving_thumbnail_time_queues_generation_without_interrupting_playback(): void
    {
        $video = $this->video();

        Livewire::test(EditVideo::class, ['record' => $video->uuid])
            ->fillForm(['thumbnail_offset_seconds' => 1.2])->call('save')->assertHasNoFormErrors();

        $this->assertSame(1.2, $video->fresh()->thumbnail_offset_seconds);
        $this->assertSame(VideoStatus::Ready, $video->fresh()->status);
        $this->assertSame('thumbnails/previous.jpg', $video->fresh()->thumbnail_path);
        Queue::assertPushed(GenerateVideoThumbnail::class, fn ($job) => $job->videoId === $video->id);
    }

    public function test_generate_action_saves_time_and_queues_only_one_generation(): void
    {
        $video = $this->video();

        Livewire::test(EditVideo::class, ['record' => $video->uuid])
            ->fillForm(['thumbnail_offset_seconds' => 0.8])->callAction('regenerateThumbnail')->assertHasNoFormErrors();

        $this->assertSame(0.8, $video->fresh()->thumbnail_offset_seconds);
        Queue::assertPushed(GenerateVideoThumbnail::class, 1);
    }

    public function test_empty_time_restores_automatic_selection_and_invalid_times_are_rejected(): void
    {
        $video = $this->video(['thumbnail_offset_seconds' => 1]);
        Livewire::test(EditVideo::class, ['record' => $video->uuid])
            ->fillForm(['thumbnail_offset_seconds' => null])->call('save')->assertHasNoFormErrors();
        $this->assertNull($video->fresh()->thumbnail_offset_seconds);
        Queue::assertPushed(GenerateVideoThumbnail::class, 1);

        foreach ([-1, 2, 100] as $offset) {
            Livewire::test(EditVideo::class, ['record' => $video->uuid])
                ->fillForm(['thumbnail_offset_seconds' => $offset])->call('save')->assertHasFormErrors(['thumbnail_offset_seconds']);
        }
        Queue::assertPushed(GenerateVideoThumbnail::class, 1);
        $this->assertNull($video->fresh()->thumbnail_offset_seconds);
    }

    public function test_regeneration_selects_the_requested_frame_and_replaces_only_the_old_thumbnail(): void
    {
        $video = $this->video(['thumbnail_offset_seconds' => 0.1]);
        $disk = Storage::disk('videos');
        (new Process([config('video.ffmpeg'), '-nostdin', '-v', 'error', '-f', 'lavfi', '-i', 'color=c=red:s=64x36:r=2:d=1', '-f', 'lavfi', '-i', 'color=c=blue:s=64x36:r=2:d=1', '-filter_complex', '[0:v][1:v]concat=n=2:v=1:a=0,format=yuv420p[v]', '-map', '[v]', '-c:v', 'libx264', '-threads', '1', '-f', 'mp4', '-y', $disk->path($video->storage_path)]))->setTimeout(20)->mustRun();
        $originalHash = hash_file('sha256', $disk->path($video->storage_path));

        (new GenerateVideoThumbnail($video->id))->handle(app(VideoInspector::class), app(MediaLock::class));
        $firstPath = $video->fresh()->thumbnail_path;
        $red = $this->pixel($disk->path($firstPath));
        $this->assertGreaterThan($red[2], $red[0]);
        $disk->assertMissing('thumbnails/previous.jpg');

        $video->update(['thumbnail_offset_seconds' => 1.2]);
        (new GenerateVideoThumbnail($video->id))->handle(app(VideoInspector::class), app(MediaLock::class));
        $video->refresh();
        $blue = $this->pixel($disk->path($video->thumbnail_path));
        $this->assertGreaterThan($blue[0], $blue[2]);
        $this->assertNotSame($firstPath, $video->thumbnail_path);
        $disk->assertMissing($firstPath);
        $this->assertNull($video->thumbnail_error);
        $this->assertSame(VideoStatus::Ready, $video->status);
        $this->assertSame($originalHash, hash_file('sha256', $disk->path($video->storage_path)));
    }

    private function pixel(string $path): array
    {
        $process = new Process([config('video.ffmpeg'), '-nostdin', '-v', 'error', '-i', $path, '-vf', 'scale=1:1', '-pix_fmt', 'rgb24', '-f', 'rawvideo', 'pipe:1']);
        $process->setTimeout(20)->mustRun();

        return array_values(unpack('C3', $process->getOutput()));
    }

    public function test_generation_failure_preserves_existing_thumbnail_original_and_ready_status(): void
    {
        $video = $this->video(['thumbnail_offset_seconds' => 1]);
        config(['video.ffmpeg' => '/nonexistent/ffmpeg']);
        try {
            (new GenerateVideoThumbnail($video->id))->handle(app(VideoInspector::class), app(MediaLock::class));
            $this->fail('Expected generation to fail.');
        } catch (ProcessFailedException) {
            $this->assertSame('thumbnails/previous.jpg', $video->fresh()->thumbnail_path);
        }
        $this->assertSame('previous preview', Storage::disk('videos')->get('thumbnails/previous.jpg'));
        Storage::disk('videos')->assertExists($video->storage_path);
        $this->assertSame(VideoStatus::Ready, $video->fresh()->status);
        $this->assertNotNull($video->fresh()->thumbnail_error);
    }

    public function test_a_newer_time_selection_wins_over_an_in_flight_generation(): void
    {
        $video = $this->video(['thumbnail_offset_seconds' => 0.5]);
        $inspector = $this->mock(VideoInspector::class);
        $inspector->shouldReceive('thumbnail')->once()->andReturnUsing(function () use ($video): string {
            Video::findOrFail($video->id)->update(['thumbnail_offset_seconds' => 1.2]);
            Storage::disk('videos')->put('thumbnails/stale.jpg', 'stale image');

            return 'thumbnails/stale.jpg';
        });

        (new GenerateVideoThumbnail($video->id))->handle($inspector, app(MediaLock::class));

        $this->assertSame('thumbnails/previous.jpg', $video->fresh()->thumbnail_path);
        $this->assertSame(1.2, $video->fresh()->thumbnail_offset_seconds);
        Storage::disk('videos')->assertMissing('thumbnails/stale.jpg');
        Storage::disk('videos')->assertExists('thumbnails/previous.jpg');
    }

    public function test_disabled_generation_and_deleted_videos_do_not_run_ffmpeg(): void
    {
        $video = $this->video();
        $inspector = $this->mock(VideoInspector::class);
        $inspector->shouldNotReceive('thumbnail');
        config(['video.thumbnails' => false]);
        Livewire::test(EditVideo::class, ['record' => $video->uuid])->assertActionDisabled('regenerateThumbnail');
        (new GenerateVideoThumbnail($video->id))->handle($inspector, app(MediaLock::class));
        config(['video.thumbnails' => true]);
        $video->delete();
        (new GenerateVideoThumbnail($video->id))->handle($inspector, app(MediaLock::class));
        Storage::disk('videos')->assertExists('thumbnails/previous.jpg');
    }

    public function test_admin_navigation_links_to_the_authenticated_frontend(): void
    {
        $this->video();
        $this->get('/admin')->assertOk()->assertSee('Video library')->assertSee(route('videos.index'), false);
    }
}
