<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Filament\Pages\VideoSettings;
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
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class VideoSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('videos');
        Queue::fake();
        config(['video.optimization_enabled' => false, 'video.optimization_auto' => false, 'video.thumbnails' => false]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        return $admin;
    }

    private function video(): Video
    {
        $video = Video::create([
            'uuid' => (string) Str::uuid(), 'title' => 'Settings test', 'original_filename' => 'sample.mp4',
            'mime_type' => 'video/mp4', 'file_size' => filesize(base_path('tests/Fixtures/sample.mp4')),
            'status' => VideoStatus::Ready, 'duration_seconds' => 2, 'storage_provider' => 'local',
            'storage_path' => 'originals/test', 'uploaded_by' => $this->admin()->id,
        ]);
        Storage::disk('videos')->put($video->storage_path, file_get_contents(base_path('tests/Fixtures/sample.mp4')));

        return $video;
    }

    public function test_guests_and_non_admins_cannot_read_or_change_settings(): void
    {
        $this->get('/admin/video-settings')->assertRedirect();
        $this->actingAs(User::factory()->create());
        $this->get('/admin/video-settings')->assertForbidden();
        Livewire::test(VideoSettings::class)->assertForbidden();
        $this->assertDatabaseCount('video_conversion_settings', 0);
    }

    public function test_admin_can_enable_conversion_and_automatic_upload_processing(): void
    {
        $this->admin();
        $this->get('/admin/video-settings')->assertOk()->assertSee('MP4-Konvertierung aktivieren');
        Livewire::test(VideoSettings::class)
            ->set('data.enabled', true)->set('data.automatic', true)->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('video_conversion_settings', ['id' => 1, 'enabled' => true, 'automatic' => true]);
        Livewire::test(VideoSettings::class)->assertSet('data.enabled', true)->assertSet('data.automatic', true);
        $this->assertTrue(app(ConversionSettings::class)->enabled());
        $this->assertTrue(app(ConversionSettings::class)->automatic());
    }

    public function test_defaults_come_from_configuration_until_first_save_and_workers_read_live_changes(): void
    {
        config(['video.optimization_enabled' => true, 'video.optimization_auto' => true]);
        $settings = app(ConversionSettings::class);
        $this->assertTrue($settings->automatic());
        $this->assertDatabaseCount('video_conversion_settings', 0);
        $this->admin();
        Livewire::test(VideoSettings::class)->set('data.enabled', false)->call('save')->assertHasNoErrors();
        $this->assertFalse($settings->enabled());
        $this->assertFalse($settings->automatic());
        $this->assertDatabaseHas('video_conversion_settings', ['id' => 1, 'enabled' => false, 'automatic' => false]);
        Livewire::test(VideoSettings::class)->set('data.enabled', true)->call('save');
        $this->assertTrue($settings->enabled());
        $this->assertFalse($settings->automatic());
        $this->assertDatabaseCount('video_conversion_settings', 1);
    }

    public function test_settings_are_validated_and_save_rechecks_administrator_access(): void
    {
        $this->admin();
        Livewire::test(VideoSettings::class)->set('data.enabled', 'invalid')->call('save')->assertHasErrors(['data.enabled' => 'boolean']);
        $component = Livewire::test(VideoSettings::class);
        $this->actingAs(User::factory()->create());
        $component->call('save')->assertForbidden();
        $this->assertDatabaseCount('video_conversion_settings', 0);
    }

    public function test_saved_settings_control_new_conversion_requests_and_pending_jobs(): void
    {
        $video = $this->video();
        $settings = app(ConversionSettings::class);
        $settings->save(true, false);
        app(VideoOptimization::class)->queue($video);
        $job = new OptimizeVideo($video->id, $video->optimization_token);
        $settings->save(false, false);
        $this->mock(VideoOptimizer::class)->shouldNotReceive('encode');
        $job->handle(app(VideoOptimizer::class), app(MediaLock::class));
        $this->assertSame('failed', $video->fresh()->optimization_status);
        $this->assertSame(0, $video->fresh()->optimization_reserved_bytes);
        $this->assertSame(VideoStatus::Ready, $video->fresh()->status);
        Storage::disk('videos')->assertExists($video->storage_path);
        try {
            app(VideoOptimization::class)->queue($video);
            $this->fail('Disabled conversion should be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        Queue::assertPushed(OptimizeVideo::class, 1);
    }

    public function test_admin_setting_controls_the_per_video_conversion_action(): void
    {
        $video = $this->video();
        Livewire::test(EditVideo::class, ['record' => $video->uuid])->assertActionHidden('optimize');
        app(ConversionSettings::class)->save(true, false);
        Livewire::test(EditVideo::class, ['record' => $video->uuid])->assertActionVisible('optimize');
        app(ConversionSettings::class)->save(false, false);
        Livewire::test(EditVideo::class, ['record' => $video->uuid])->assertActionHidden('optimize');
    }

    public function test_automatic_conversion_uses_saved_setting_instead_of_environment_default(): void
    {
        $video = $this->video();
        app(ConversionSettings::class)->save(true, true);
        $video->update(['status' => VideoStatus::Processing]);
        (new ProcessVideo($video->id))->handle(app(VideoInspector::class), app(MediaLock::class));
        Queue::assertPushed(OptimizeVideo::class, 1);
        $this->assertSame('queued', $video->fresh()->optimization_status);
    }

    public function test_disabling_conversion_does_not_interrupt_running_conversion_or_remove_existing_copy(): void
    {
        $video = $this->video();
        $video->update(app(VideoInspector::class)->inspect($video));
        app(ConversionSettings::class)->save(true, true);
        app(VideoOptimization::class)->queue($video);
        $this->mock(VideoOptimizer::class, function ($mock): void {
            $mock->makePartial();
            $mock->shouldReceive('encode')->once()->andReturnUsing(function ($record, $token): array {
                $metadata = (new VideoOptimizer)->encode($record, $token);
                app(ConversionSettings::class)->save(false, false);

                return $metadata;
            });
        });
        (new OptimizeVideo($video->id, $video->optimization_token))->handle(app(VideoOptimizer::class), app(MediaLock::class));
        $video->refresh();
        $this->assertTrue($video->hasOptimizedCopy());
        Storage::disk('videos')->assertExists([$video->storage_path, $video->optimized_path]);
        $this->get(route('videos.stream', ['video' => $video, 'quality' => 'optimized']))->assertOk();
        $this->assertFalse(app(ConversionSettings::class)->enabled());
    }
}
