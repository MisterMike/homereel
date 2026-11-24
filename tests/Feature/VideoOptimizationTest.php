<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Filament\Resources\Videos\Pages\EditVideo;
use App\Jobs\OptimizeVideo;
use App\Jobs\ProcessVideo;
use App\Models\User;
use App\Models\Video;
use App\Services\Video\ConversionSettings;
use App\Services\Video\MediaLock;
use App\Services\Video\VideoInspector;
use App\Services\Video\VideoOptimization;
use App\Services\Video\VideoOptimizer;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class VideoOptimizationTest extends TestCase
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
        config(['video.optimization_enabled' => true, 'video.optimization_auto' => false]);
    }

    private function video(): Video
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $video = Video::create([
            'uuid' => (string) Str::uuid(), 'title' => 'Family film', 'original_filename' => 'film.mp4',
            'mime_type' => 'video/mp4', 'file_size' => filesize(base_path('tests/Fixtures/sample.mp4')),
            'duration_seconds' => 2, 'status' => VideoStatus::Ready, 'storage_provider' => 'local',
            'storage_path' => 'originals/test', 'uploaded_by' => $admin->id, 'prototype_available' => true,
        ]);
        Storage::disk('videos')->put($video->storage_path, file_get_contents(base_path('tests/Fixtures/sample.mp4')));
        $metadata = app(VideoInspector::class)->inspect($video);
        $video->update($metadata);

        return $video;
    }

    private function runJob(Video $video): void
    {
        (new OptimizeVideo($video->id, $video->optimization_token))->handle(app(VideoOptimizer::class), app(MediaLock::class));
    }

    public function test_admin_can_queue_a_separate_long_running_job_only_once(): void
    {
        $video = $this->video();
        Livewire::test(EditVideo::class, ['record' => $video->uuid])->callAction('optimize');
        $video->refresh();
        $this->assertSame('queued', $video->optimization_status);
        $this->assertGreaterThan(0, $video->optimization_reserved_bytes);
        $this->assertSame(VideoStatus::Ready, $video->status);
        Queue::assertPushed(OptimizeVideo::class, fn ($job) => $job->connection === 'transcoding' && $job->queue === 'transcoding' && $job->timeout < config('queue.connections.transcoding.retry_after'));
        try {
            app(VideoOptimization::class)->queue($video);
            $this->fail('A duplicate job was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        Queue::assertPushed(OptimizeVideo::class, 1);
    }

    public function test_queue_insertion_and_storage_reservation_roll_back_together(): void
    {
        $video = $this->video();
        Queue::swap($this->realQueue);
        try {
            DB::transaction(function () use ($video): void {
                app(VideoOptimization::class)->queue($video);
                $this->assertDatabaseHas('jobs', ['queue' => 'transcoding']);
                throw new \RuntimeException('Rollback the outer transaction');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Rollback the outer transaction', $exception->getMessage());
        }
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame(0, $video->fresh()->optimization_reserved_bytes);
        $this->assertSame('not_requested', $video->fresh()->optimization_status);
    }

    public function test_real_encoding_publishes_verified_private_mp4_and_preserves_original(): void
    {
        $video = $this->video();
        $disk = Storage::disk('videos');
        $original = hash_file('sha256', $disk->path($video->storage_path));
        app(VideoOptimization::class)->queue($video);
        $this->runJob($video->refresh());
        $video->refresh();
        $this->assertTrue($video->hasOptimizedCopy());
        $this->assertSame(VideoStatus::Ready, $video->status);
        $this->assertSame(0, $video->optimization_reserved_bytes);
        $this->assertSame($original, hash_file('sha256', $disk->path($video->storage_path)));
        $this->assertSame(0600, fileperms($disk->path($video->optimized_path)) & 0777);
        $this->assertGreaterThan(0, $video->optimized_file_size);
        $this->assertLessThanOrEqual(720, $video->optimized_height);
        $bytes = $disk->get($video->optimized_path);
        $this->assertLessThan(strpos($bytes, 'mdat'), strpos($bytes, 'moov'), 'faststart metadata must precede video data');
        $metadata = app(VideoInspector::class)->inspect(new Video(['storage_path' => $video->optimized_path, 'mime_type' => 'video/mp4']));
        $this->assertTrue($metadata['browser_compatible']);
        $this->assertEqualsWithDelta($video->duration_seconds, $metadata['duration_seconds'], 0.1);
        $path = $video->optimized_path;
        $this->runJob($video);
        $this->assertSame($path, $video->fresh()->optimized_path);
        $this->get(route('videos.show', $video))->assertOk()->assertSee('Wiedergabequalität')->assertSee('Optimiert');
    }

    public function test_failed_conversion_keeps_playable_original_and_releases_space(): void
    {
        $video = $this->video();
        app(VideoOptimization::class)->queue($video);
        $this->mock(VideoOptimizer::class, function ($mock) use ($video): void {
            $mock->shouldReceive('encode')->once()->andReturnUsing(function () use ($video): never {
                Storage::disk('videos')->put('optimized/'.$video->uuid.'-'.$video->optimization_token.'.part.mp4', 'partial');
                throw new \RuntimeException('FFmpeg failed');
            });
            $mock->shouldReceive('temporaryPath')->andReturn('optimized/'.$video->uuid.'-'.$video->optimization_token.'.part.mp4');
            $mock->shouldReceive('finalPath')->andReturn('optimized/'.$video->uuid.'-'.$video->optimization_token.'.mp4');
        });
        try {
            $this->runJob($video);
            $this->fail('Conversion should fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('FFmpeg failed', $exception->getMessage());
        }
        $video->refresh();
        $this->assertSame('failed', $video->optimization_status);
        $this->assertSame(VideoStatus::Ready, $video->status);
        $this->assertSame(0, $video->optimization_reserved_bytes);
        $this->assertSame([], Storage::disk('videos')->files('optimized'));
        $this->get(route('videos.stream', $video))->assertOk();
        app(VideoOptimization::class)->queue($video);
        $this->assertSame('queued', $video->fresh()->optimization_status);
    }

    /** @return array<string, array{int, int}> */
    public static function resolutionLimits(): array
    {
        return ['720p' => [720, 720], '1080p' => [1080, 1080], 'original dimensions' => [0, 1080]];
    }

    #[DataProvider('resolutionLimits')]
    public function test_encoding_uses_the_selected_quality_and_resolution(int $maxHeight, int $expectedHeight): void
    {
        $video = $this->video();
        (new Process([
            config('video.ffmpeg'), '-nostdin', '-v', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=1920x1080:rate=5',
            '-t', '0.4', '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-threads', '1',
            '-f', 'mp4', '-y', Storage::disk('videos')->path($video->storage_path),
        ]))->setTimeout(30)->mustRun();
        $video->update([...app(VideoInspector::class)->inspect($video), 'file_size' => Storage::disk('videos')->size($video->storage_path)]);
        app(ConversionSettings::class)->save(true, false, [
            'crf' => 18, 'preset' => 'fast', 'max_height' => $maxHeight, 'video_bitrate' => 8000, 'audio_bitrate' => 192,
        ]);
        app(VideoOptimization::class)->queue($video);
        $this->runJob($video->refresh());

        $video->refresh();
        $this->assertTrue($video->hasOptimizedCopy());
        $this->assertSame($expectedHeight, $video->optimized_height);
        $this->assertSame(18, $video->optimization_profile['crf']);
        $this->assertSame(8000, $video->optimization_profile['video_bitrate']);
    }

    public function test_waiting_jobs_pick_up_saved_quality_and_running_jobs_keep_their_profile(): void
    {
        $video = $this->video();
        app(VideoOptimization::class)->queue($video);
        $oldReservation = $video->optimization_reserved_bytes;
        app(ConversionSettings::class)->save(true, false, ['crf' => 18, 'max_height' => 1080, 'video_bitrate' => 8000, 'audio_bitrate' => 192]);
        $this->mock(VideoOptimizer::class, function ($mock) use ($oldReservation): void {
            $mock->makePartial();
            $mock->shouldReceive('encode')->once()->andReturnUsing(function (Video $record, string $token) use ($oldReservation): array {
                $this->assertSame(18, $record->optimization_profile['crf']);
                $this->assertGreaterThan($oldReservation, $record->optimization_reserved_bytes);
                app(ConversionSettings::class)->save(true, false, ConversionSettings::DEFAULT_PROFILE);
                $this->assertSame(18, $record->optimization_profile['crf']);

                return (new VideoOptimizer)->encode($record, $token);
            });
        });
        $this->runJob($video->refresh());

        $this->assertTrue($video->fresh()->hasOptimizedCopy());
        $this->assertSame(18, $video->fresh()->optimization_profile['crf']);
        $this->assertSame(23, app(ConversionSettings::class)->profile()['crf']);
    }

    public function test_quality_increase_cannot_exceed_the_storage_quota_for_a_waiting_job(): void
    {
        $video = $this->video();
        app(VideoOptimization::class)->queue($video);
        config(['video.storage_limit_bytes' => $video->file_size + $video->optimization_reserved_bytes + 1]);
        app(ConversionSettings::class)->save(true, false, ['video_bitrate' => 10000]);
        $this->mock(VideoOptimizer::class, function ($mock): void {
            $mock->makePartial();
            $mock->shouldNotReceive('encode');
        });
        try {
            $this->runJob($video->refresh());
            $this->fail('A larger conversion reservation should exceed the quota.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $this->assertSame('failed', $video->fresh()->optimization_status);
        $this->assertSame(0, $video->fresh()->optimization_reserved_bytes);
        $this->assertSame(VideoStatus::Ready, $video->fresh()->status);
        Storage::disk('videos')->assertExists($video->storage_path);
    }

    public function test_disabled_optimization_and_storage_shortage_do_not_enqueue_jobs(): void
    {
        $video = $this->video();
        foreach (['disabled', 'quota'] as $case) {
            config(['video.optimization_enabled' => $case !== 'disabled', 'video.storage_limit_bytes' => 1]);
            try {
                app(VideoOptimization::class)->queue($video);
                $this->fail('Optimization should be rejected.');
            } catch (HttpException $exception) {
                $this->assertSame($case === 'disabled' ? 409 : 422, $exception->getStatusCode());
            }
        }
        Queue::assertNothingPushed();
        $this->assertSame(0, $video->fresh()->optimization_reserved_bytes);
    }

    public function test_reservations_and_existing_copies_count_towards_upload_quota(): void
    {
        $video = $this->video();
        app(VideoOptimization::class)->queue($video);
        config(['video.storage_limit_bytes' => $video->file_size + $video->optimization_reserved_bytes + 5]);
        $this->postJson('/uploads', ['filename' => 'next.mp4', 'title' => 'Next', 'mime_type' => 'video/mp4', 'size' => 10])->assertUnprocessable();
        $video->update(['optimization_reserved_bytes' => 0, 'optimized_file_size' => 100]);
        config(['video.storage_limit_bytes' => $video->file_size + 105]);
        $this->postJson('/uploads', ['filename' => 'next.mp4', 'title' => 'Next', 'mime_type' => 'video/mp4', 'size' => 10])->assertUnprocessable();
    }

    public function test_deleted_video_is_not_encoded_and_cannot_be_published(): void
    {
        $video = $this->video();
        app(VideoOptimization::class)->queue($video);
        $video->delete();
        $this->runJob($video);
        $this->assertSame(0, $video->fresh()->optimization_reserved_bytes);
        $this->assertNull($video->fresh()->optimized_path);
        Storage::disk('videos')->assertExists($video->storage_path);
    }

    public function test_video_deleted_during_encoding_never_publishes_the_copy(): void
    {
        $video = $this->video();
        app(VideoOptimization::class)->queue($video);
        $this->mock(VideoOptimizer::class, function ($mock) use ($video): void {
            $mock->makePartial();
            $mock->shouldReceive('encode')->once()->andReturnUsing(function ($record, $token) use ($video): array {
                $metadata = (new VideoOptimizer)->encode($record, $token);
                $video->delete();

                return $metadata;
            });
        });
        $this->runJob($video);
        $this->assertNull($video->fresh()->optimized_path);
        $this->assertSame(0, $video->fresh()->optimization_reserved_bytes);
        $this->assertSame([], Storage::disk('videos')->files('optimized'));
        Storage::disk('videos')->assertExists($video->storage_path);
    }

    public function test_explicit_purge_waits_for_active_encode_and_removes_both_copies(): void
    {
        $video = $this->video();
        app(VideoOptimization::class)->queue($video);
        $this->runJob($video);
        $video->refresh()->delete();
        $video->forceFill(['deleted_at' => now()->subDays(3)])->save();
        app(MediaLock::class)->run('optimization-'.$video->uuid, function () use ($video): void {
            $this->artisan('videos:purge', ['--days' => 1, '--force' => true])->assertSuccessful();
            $this->assertNotNull(Video::withTrashed()->find($video->id));
            Storage::disk('videos')->assertExists($video->storage_path);
            Storage::disk('videos')->assertExists($video->optimized_path);
        });
        $this->artisan('videos:purge', ['--days' => 1, '--force' => true])->assertSuccessful();
        $this->assertNull(Video::withTrashed()->find($video->id));
        Storage::disk('videos')->assertMissing([$video->storage_path, $video->optimized_path]);
    }

    public function test_stale_job_cannot_release_a_new_attempts_reservation(): void
    {
        $video = $this->video();
        app(VideoOptimization::class)->queue($video);
        $oldJob = new OptimizeVideo($video->id, $video->optimization_token);
        $reservation = $video->optimization_reserved_bytes;
        $video->update(['optimization_token' => (string) Str::uuid()]);
        $oldJob->handle(app(VideoOptimizer::class), app(MediaLock::class));
        $oldJob->failed(new \RuntimeException('Old attempt timed out'));
        $this->assertSame($reservation, $video->fresh()->optimization_reserved_bytes);
        $this->assertSame('queued', $video->fresh()->optimization_status);
    }

    public function test_non_admin_cannot_use_optimization_action(): void
    {
        $video = $this->video();
        $this->actingAs(User::factory()->create());
        Livewire::test(EditVideo::class, ['record' => $video->uuid])->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_inspection_can_automatically_queue_optimization(): void
    {
        $video = $this->video();
        $video->update(['status' => VideoStatus::Processing]);
        config(['video.optimization_auto' => true, 'video.thumbnails' => false]);
        (new ProcessVideo($video->id))->handle(app(VideoInspector::class), app(MediaLock::class));
        $this->assertSame(VideoStatus::Ready, $video->fresh()->status);
        Queue::assertPushed(OptimizeVideo::class, 1);
    }
}
