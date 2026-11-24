<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Filament\Resources\Videos\Pages\EditVideo;
use App\Filament\Resources\Videos\Pages\ListVideos;
use App\Jobs\DetectVideoRecordingDate;
use App\Jobs\ProcessVideo;
use App\Models\Album;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class VideoRecordingDateTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('videos');
        Queue::fake();
        config(['video.thumbnails' => false, 'video.optimization_auto' => false]);
        $this->administrator = User::factory()->create(['is_admin' => true]);
        $this->actingAs($this->administrator);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @param array<string, mixed> $attributes */
    private function video(array $attributes = []): Video
    {
        $uuid = (string) Str::uuid();
        $video = Video::create(array_merge([
            'uuid' => $uuid, 'title' => 'Family archive', 'original_filename' => 'archive.mp4',
            'mime_type' => 'video/mp4', 'file_size' => 100, 'status' => VideoStatus::Ready,
            'storage_provider' => 'local', 'storage_path' => 'originals/'.$uuid.'.mp4',
            'uploaded_by' => $this->administrator->id, 'prototype_available' => true,
        ], $attributes));
        Storage::disk('videos')->put($video->storage_path, file_get_contents(base_path('tests/Fixtures/sample.mp4')));

        return $video;
    }

    /** Stream-only fixtures clear the MP4 movie header date while retaining the track dates. */
    private function embedDate(Video $video, string $tag, string $value, bool $stream = false): void
    {
        $command = [
            config('video.ffmpeg'), '-nostdin', '-v', 'error', '-i', base_path('tests/Fixtures/sample.mp4'),
            '-c', 'copy', '-map_metadata', '-1', '-metadata', $tag.'='.$value,
        ];
        if ($tag === 'com.apple.quicktime.creationdate') {
            array_push($command, '-movflags', 'use_metadata_tags');
        }
        array_push($command, '-y', Storage::disk('videos')->path($video->storage_path));
        (new Process($command))->setTimeout(30)->mustRun();
        if ($stream) {
            $bytes = Storage::disk('videos')->get($video->storage_path);
            $header = strpos($bytes, 'mvhd');
            $this->assertNotFalse($header);
            $this->assertSame(0, ord($bytes[$header + 4]));
            Storage::disk('videos')->put($video->storage_path, substr_replace($bytes, pack('N', 0), $header + 8, 4));
        }
    }

    /** @return array<string, array{string, string, bool, ?string}> */
    public static function embeddedDates(): array
    {
        return [
            'container timestamp' => ['creation_time', '2012-07-14T15:30:00Z', false, '2012-07-14'],
            'video stream timestamp' => ['creation_time', '2003-05-06T12:00:00Z', true, '2003-05-06'],
            'Apple camera date keeps local day' => ['com.apple.quicktime.creationdate', '2008-01-02T00:30:00+0200', false, '2008-01-02'],
            'malformed date' => ['com.apple.quicktime.creationdate', 'not a date', false, null],
            'invalid calendar day' => ['com.apple.quicktime.creationdate', '2008-02-30T12:00:00Z', false, null],
            'placeholder epoch' => ['creation_time', '1970-01-01T00:00:00Z', false, null],
            'future date' => ['com.apple.quicktime.creationdate', '2999-01-01T00:00:00Z', false, null],
        ];
    }

    #[DataProvider('embeddedDates')]
    public function test_inspection_extracts_plausible_embedded_dates(string $tag, string $value, bool $stream, ?string $expected): void
    {
        $video = $this->video(['status' => VideoStatus::Processing]);
        $this->embedDate($video, $tag, $value, $stream);

        (new ProcessVideo($video->id))->handle(app(VideoInspector::class), app(MediaLock::class));

        $this->assertSame(VideoStatus::Ready, $video->fresh()->status);
        $this->assertSame($expected, $video->fresh()->recorded_on?->toDateString());
    }

    public function test_missing_metadata_leaves_the_date_empty_and_manual_dates_survive_reprocessing(): void
    {
        $video = $this->video(['status' => VideoStatus::Processing]);
        (new ProcessVideo($video->id))->handle(app(VideoInspector::class), app(MediaLock::class));
        $this->assertNull($video->fresh()->recorded_on);

        $video->update(['status' => VideoStatus::Processing, 'recorded_on' => '1985-06-02']);
        $this->embedDate($video, 'creation_time', '2020-08-03T12:00:00Z');
        (new ProcessVideo($video->id))->handle(app(VideoInspector::class), app(MediaLock::class));

        $this->assertSame('1985-06-02', $video->fresh()->recorded_on->toDateString());
    }

    public function test_existing_videos_can_detect_dates_without_reprocessing_or_replacing_previews(): void
    {
        $video = $this->video(['thumbnail_path' => 'thumbnails/existing.jpg']);
        $this->embedDate($video, 'creation_time', '1999-12-24T12:00:00Z');

        Livewire::test(EditVideo::class, ['record' => $video->uuid])->callAction('detectRecordedOn')->assertHasNoFormErrors();
        Queue::assertPushed(DetectVideoRecordingDate::class, fn (DetectVideoRecordingDate $job): bool => $job->videoId === $video->id);
        (new DetectVideoRecordingDate($video->id))->handle(app(VideoInspector::class), app(MediaLock::class));

        $this->assertSame('1999-12-24', $video->fresh()->recorded_on->toDateString());
        $this->assertSame(VideoStatus::Ready, $video->fresh()->status);
        $this->assertSame('thumbnails/existing.jpg', $video->fresh()->thumbnail_path);

        $video->update(['recorded_on' => '1980-05-04']);
        (new DetectVideoRecordingDate($video->id))->handle(app(VideoInspector::class), app(MediaLock::class));
        $this->assertSame('1980-05-04', $video->fresh()->recorded_on->toDateString());
    }

    public function test_manual_dates_win_if_entered_during_detection(): void
    {
        $video = $this->video();
        $inspector = $this->mock(VideoInspector::class);
        $inspector->shouldReceive('inspect')->once()->andReturnUsing(function () use ($video): array {
            $video->update(['recorded_on' => '1982-06-04']);

            return ['recorded_on' => '2010-01-01'];
        });

        (new DetectVideoRecordingDate($video->id))->handle($inspector, app(MediaLock::class));
        $this->assertSame('1982-06-04', $video->fresh()->recorded_on->toDateString());
    }

    public function test_the_date_can_be_edited_and_cleared_and_invalid_or_future_dates_are_rejected(): void
    {
        $video = $this->video();
        Livewire::test(EditVideo::class, ['record' => $video->uuid])
            ->fillForm(['recorded_on' => '1978-08-15'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('1978-08-15', $video->fresh()->recorded_on->toDateString());
        Livewire::test(EditVideo::class, ['record' => $video->uuid])
            ->fillForm(['recorded_on' => null])->call('save')->assertHasNoFormErrors();
        $this->assertNull($video->fresh()->recorded_on);

        foreach (['not-a-date', now()->addYear()->toDateString()] as $value) {
            Livewire::test(EditVideo::class, ['record' => $video->uuid])
                ->fillForm(['recorded_on' => $value])->call('save')->assertHasFormErrors(['recorded_on']);
        }
        $this->assertNull($video->fresh()->recorded_on);
    }

    public function test_library_and_administration_sort_by_recording_date_with_upload_date_as_fallback(): void
    {
        $album = Album::factory()->create();
        $old = $this->video(['title' => 'Old holiday', 'recorded_on' => '1980-06-01', 'created_at' => '2025-06-01', 'album_id' => $album->id]);
        $recent = $this->video(['title' => 'Recent holiday', 'recorded_on' => '2020-06-01', 'created_at' => '2021-06-01', 'album_id' => $album->id]);
        $unknown = $this->video(['title' => 'Unknown holiday', 'created_at' => '2010-06-01', 'album_id' => $album->id]);
        $this->video(['title' => 'Private holiday', 'recorded_on' => '2024-06-01', 'prototype_available' => false]);

        Livewire::test(ListVideos::class)->assertCanSeeTableRecords([$recent, $unknown, $old], inOrder: true);
        Livewire::test(ListVideos::class)->sortTable('recorded_on', 'asc')->assertCanSeeTableRecords([$old, $unknown, $recent], inOrder: true);

        $this->actingAs(User::factory()->create());
        foreach (['/', '/?q=holiday', '/?album='.$album->id] as $url) {
            $this->get($url)->assertOk()->assertSeeInOrder(['Recent holiday', 'Unknown holiday', 'Old holiday'])->assertDontSee('Private holiday');
        }
        $this->get(route('videos.show', $old))->assertOk()->assertSee('1. Juni 1980')->assertSee('Aufgenommen am');
        $this->get(route('videos.show', $unknown))->assertOk()->assertSee('1. Juni 2010')->assertSee('Hochgeladen am');
    }

    public function test_contributors_can_only_change_and_detect_dates_on_their_own_videos(): void
    {
        $owner = User::factory()->create(['is_contributor' => true]);
        $own = $this->video(['uploaded_by' => $owner->id]);
        $other = $this->video();
        $this->actingAs($owner);

        Livewire::test(EditVideo::class, ['record' => $own->uuid])
            ->fillForm(['recorded_on' => '1995-01-02'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('1995-01-02', $own->fresh()->recorded_on->toDateString());
        Livewire::test(EditVideo::class, ['record' => $other->uuid])->assertNotFound();
        $this->assertNull($other->fresh()->recorded_on);
        Queue::assertNothingPushed();
    }
}
