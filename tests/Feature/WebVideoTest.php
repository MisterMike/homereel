<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Jobs\OptimizeVideo;
use App\Jobs\PrepareWebVideo;
use App\Models\User;
use App\Models\Video;
use App\Notifications\VideoReady;
use App\Services\Video\ConversionSettings;
use App\Services\Video\MediaLock;
use App\Services\Video\VideoInspector;
use App\Services\Video\VideoOptimization;
use App\Services\Video\VideoOptimizer;
use App\Services\Video\VideoStorageBudget;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class WebVideoTest extends TestCase
{
    use RefreshDatabase;

    private mixed $realNotifications;

    private mixed $realQueue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('videos');
        $this->realQueue = app('queue');
        $this->realNotifications = app(Dispatcher::class);
        Queue::fake();
        Notification::fake();
        config(['video.optimization_enabled' => true, 'video.optimization_auto' => true]);
        app(ConversionSettings::class)->save(true, true, ['max_height' => 1080, 'video_bitrate' => 8000, 'crf' => 18]);
    }

    private function video(bool $highResolution = false): Video
    {
        $uploader = User::factory()->create(['is_admin' => true]);
        $this->actingAs($uploader);
        $video = Video::create([
            'uuid' => (string) Str::uuid(), 'title' => 'Sommerferien', 'original_filename' => 'film.mp4',
            'mime_type' => 'video/mp4', 'file_size' => 0, 'status' => VideoStatus::Ready, 'storage_provider' => 'local',
            'storage_path' => 'originals/'.Str::uuid(), 'uploaded_by' => $uploader->id, 'prototype_available' => true,
        ]);
        $disk = Storage::disk('videos');
        $disk->put($video->storage_path, file_get_contents(base_path('tests/Fixtures/sample.mp4')));
        if ($highResolution) {
            (new Process([
                config('video.ffmpeg'), '-nostdin', '-v', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=1920x1080:rate=5',
                '-t', '0.6', '-c:v', 'libx264', '-preset', 'ultrafast', '-threads', '1', '-pix_fmt', 'yuv420p',
                '-f', 'mp4', '-y', $disk->path($video->storage_path),
            ]))->setTimeout(30)->mustRun();
        }
        $video->update([...app(VideoInspector::class)->inspect($video), 'file_size' => $disk->size($video->storage_path)]);

        return $video;
    }

    private function prepare(Video $video): void
    {
        app(VideoOptimization::class)->queue($video, standard: true);
        (new PrepareWebVideo($video->id, $video->web_token))->handle(app(VideoOptimizer::class), app(MediaLock::class));
        $video->refresh();
    }

    public function test_standard_is_published_and_emailed_before_hd_is_processed(): void
    {
        $video = $this->video(highResolution: true);
        $original = hash_file('sha256', Storage::disk('videos')->path($video->storage_path));
        app(VideoOptimization::class)->queue($video, standard: true);
        Queue::assertPushed(PrepareWebVideo::class, fn ($job): bool => $job->queue === 'standard' && $job->connection === 'transcoding');
        Queue::assertNotPushed(OptimizeVideo::class);
        $this->get(route('videos.stream', $video))->assertStatus(409);
        $this->get(route('videos.stream', ['video' => $video, 'quality' => 'original']))->assertUnprocessable();
        $job = unserialize(serialize(new PrepareWebVideo($video->id, $video->web_token)));
        $job->handle(app(VideoOptimizer::class), app(MediaLock::class));
        $video->refresh();
        $this->assertTrue($video->hasWebCopy());
        $this->assertFalse($video->hasOptimizedCopy());
        $this->assertSame(720, $video->web_height);
        $this->assertSame('queued', $video->optimization_status);
        $this->assertSame(0, $video->web_reserved_bytes);
        $this->assertGreaterThan(0, $video->optimization_reserved_bytes);
        $this->assertNotNull($video->ready_notified_at);
        Notification::assertSentTo(User::find($video->uploaded_by), VideoReady::class, 1);
        $this->assertSame(Storage::disk('videos')->get($video->web_path), $this->get(route('videos.stream', $video))->assertOk()->streamedContent());
        $this->get(route('videos.stream', ['video' => $video, 'quality' => 'hd']))->assertNotFound();
        $this->get(route('videos.show', $video))->assertOk()->assertSee('HD wird vorbereitet')->assertDontSee('quality=original', false);
        (new OptimizeVideo($video->id, $video->optimization_token))->handle(app(VideoOptimizer::class), app(MediaLock::class));
        $video->refresh();
        $this->assertTrue($video->hasOptimizedCopy());
        $this->assertSame(1080, $video->optimized_height);
        $this->assertSame($original, hash_file('sha256', Storage::disk('videos')->path($video->storage_path)));
        $job->handle(app(VideoOptimizer::class), app(MediaLock::class));
        Notification::assertSentTo(User::find($video->uploaded_by), VideoReady::class, 1);
        $this->get(route('videos.show', $video))->assertOk()->assertSee('Wiedergabequalität')->assertSee('Standard')->assertSee('Höhere Qualität')->assertDontSee('HD wird vorbereitet');
    }

    public function test_small_source_skips_redundant_hd_and_is_remuxed_without_reencoding(): void
    {
        $video = $this->video();
        $this->prepare($video);
        Queue::assertNotPushed(OptimizeVideo::class);
        $this->assertSame($video->height, $video->web_height);
        $hashes = [];
        foreach ([$video->storage_path, $video->web_path] as $path) {
            $process = new Process([config('video.ffmpeg'), '-v', 'error', '-protocol_whitelist', 'file', '-i', Storage::disk('videos')->path($path), '-map', '0:v:0', '-c', 'copy', '-f', 'h264', '-']);
            $process->setTimeout(30)->mustRun();
            $hashes[] = hash('sha256', $process->getOutput());
        }
        $this->assertSame($hashes[0], $hashes[1], 'The compressed video stream must be copied unchanged.');
        $bytes = Storage::disk('videos')->get($video->web_path);
        $this->assertLessThan(strpos($bytes, 'mdat'), strpos($bytes, 'moov'));
        $this->assertSame(0600, fileperms(Storage::disk('videos')->path($video->web_path)) & 0777);
    }

    public function test_hd_is_skipped_when_settings_cannot_improve_on_standard_resolution(): void
    {
        app(ConversionSettings::class)->save(true, true, ['max_height' => 720]);
        $video = $this->video(highResolution: true);
        $this->prepare($video);
        Queue::assertNotPushed(OptimizeVideo::class);
        $this->assertTrue($video->hasWebCopy());
    }

    public function test_hd_failure_does_not_interrupt_standard_playback_or_send_another_mail(): void
    {
        $video = $this->video(highResolution: true);
        $this->prepare($video);
        $this->mock(VideoOptimizer::class, function ($mock): void {
            $mock->makePartial();
            $mock->shouldReceive('encode')->once()->andThrow(new \RuntimeException('Encoding failed'));
        });
        try {
            (new OptimizeVideo($video->id, $video->optimization_token))->handle(app(VideoOptimizer::class), app(MediaLock::class));
            $this->fail('HD should fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Encoding failed', $exception->getMessage());
        }
        $video->refresh();
        $this->assertSame('failed', $video->optimization_status);
        $this->assertSame(0, $video->optimization_reserved_bytes);
        $this->get(route('videos.stream', $video))->assertOk();
        Notification::assertSentTo(User::find($video->uploaded_by), VideoReady::class, 1);
    }

    public function test_deleted_during_standard_encoding_does_not_publish_or_notify(): void
    {
        $video = $this->video();
        app(VideoOptimization::class)->queue($video, standard: true);
        $this->mock(VideoOptimizer::class, function ($mock) use ($video): void {
            $mock->makePartial();
            $mock->shouldReceive('encode')->once()->andReturnUsing(function (Video $record, string $token, bool $standard) use ($video): array {
                $metadata = (new VideoOptimizer)->encode($record, $token, $standard);
                $video->delete();

                return $metadata;
            });
        });
        (new PrepareWebVideo($video->id, $video->web_token))->handle(app(VideoOptimizer::class), app(MediaLock::class));
        $this->assertNull($video->fresh()->web_path);
        $this->assertSame(0, $video->fresh()->web_reserved_bytes);
        $this->assertSame([], Storage::disk('videos')->files('web'));
        Notification::assertNothingSent();
        Queue::assertNotPushed(OptimizeVideo::class);
    }

    public function test_failed_standard_attempt_releases_space_without_affecting_hd_and_can_be_retried(): void
    {
        $video = $this->video();
        $video->update(['optimized_path' => 'optimized/existing.mp4', 'optimization_status' => 'ready', 'optimized_file_size' => 8]);
        Storage::disk('videos')->put($video->optimized_path, 'EXISTING');
        app(VideoOptimization::class)->queue($video, standard: true);
        $job = new PrepareWebVideo($video->id, $video->web_token);
        Storage::disk('videos')->put(app(VideoOptimizer::class)->temporaryPath($video, $video->web_token, true), 'partial');
        $job->failed(new \RuntimeException('Timeout'));
        $video->refresh();
        $this->assertSame('failed', $video->web_status);
        $this->assertSame(0, $video->web_reserved_bytes);
        $this->assertTrue($video->hasOptimizedCopy());
        $this->assertSame('EXISTING', $this->get(route('videos.stream', $video))->assertOk()->streamedContent());
        app(VideoOptimization::class)->queue($video, standard: true);
        $reservation = $video->web_reserved_bytes;
        $job->failed(new \RuntimeException('Stale timeout'));
        $this->assertSame($reservation, $video->fresh()->web_reserved_bytes);
        Notification::assertNothingSent();
    }

    public function test_all_copies_and_reservations_count_towards_quota_and_purge_removes_them(): void
    {
        $video = $this->video(highResolution: true);
        $this->prepare($video);
        $this->assertSame($video->file_size + $video->web_file_size + $video->optimization_reserved_bytes, app(VideoStorageBudget::class)->used());
        (new OptimizeVideo($video->id, $video->optimization_token))->handle(app(VideoOptimizer::class), app(MediaLock::class));
        $video->refresh();
        $this->assertSame($video->file_size + $video->web_file_size + $video->optimized_file_size, app(VideoStorageBudget::class)->used());
        $video->delete();
        $video->forceFill(['deleted_at' => now()->subDays(3)])->save();
        app(MediaLock::class)->run('web-optimization-'.$video->uuid, function () use ($video): void {
            $this->artisan('videos:purge', ['--days' => 1, '--force' => true])->assertSuccessful();
            $this->assertNotNull(Video::withTrashed()->find($video->id));
        });
        $this->artisan('videos:purge', ['--days' => 1, '--force' => true])->assertSuccessful();
        Storage::disk('videos')->assertMissing([$video->storage_path, $video->web_path, $video->optimized_path]);
        $this->assertSame(0, app(VideoStorageBudget::class)->used());
    }

    public function test_notification_links_to_the_video_and_checks_account_and_deletion_state(): void
    {
        $video = $this->video();
        $uploader = User::find($video->uploaded_by);
        $notification = new VideoReady($video);
        $mail = $notification->toMail($uploader);
        $this->assertSame('Dein Video ist bereit', $mail->subject);
        $this->assertSame(route('videos.show', $video), $mail->actionUrl);
        $this->assertSame('database', $notification->connection);
        $this->assertFalse($notification->afterCommit);
        $this->assertTrue($notification->shouldSend($uploader, 'mail'));
        $uploader->is_active = false;
        $this->assertFalse($notification->shouldSend($uploader, 'mail'));
        $uploader->is_active = true;
        $video->delete();
        $this->assertFalse($notification->shouldSend($uploader, 'mail'));
    }

    public function test_ready_notification_is_queued_once_on_the_ordinary_mail_worker(): void
    {
        Notification::swap($this->realNotifications);
        $video = $this->video(highResolution: true);
        $this->prepare($video);
        Queue::assertPushed(SendQueuedNotifications::class, fn ($job): bool => $job->notification instanceof VideoReady && $job->connection === 'database' && $job->queue === 'default' && $job->afterCommit === false);
        (new OptimizeVideo($video->id, $video->optimization_token))->handle(app(VideoOptimizer::class), app(MediaLock::class));
        Queue::assertPushed(SendQueuedNotifications::class, 1);
    }

    public function test_standard_job_and_reservation_are_rolled_back_together(): void
    {
        $video = $this->video();
        Queue::swap($this->realQueue);
        try {
            DB::transaction(function () use ($video): void {
                app(VideoOptimization::class)->queue($video, standard: true);
                $this->assertDatabaseHas('jobs', ['queue' => 'standard']);
                throw new \RuntimeException('Rollback');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Rollback', $exception->getMessage());
        }
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame(0, $video->fresh()->web_reserved_bytes);
        $this->assertSame('not_requested', $video->fresh()->web_status);
    }
}
