<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Filament\Pages\QueueOverview;
use App\Jobs\DetectVideoRecordingDate;
use App\Jobs\OptimizeVideo;
use App\Jobs\PrepareWebVideo;
use App\Jobs\ProcessVideo;
use App\Models\User;
use App\Models\Video;
use App\Notifications\LibraryInvitation;
use App\Services\Video\QueuedVideoReference;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class QueueOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->freezeTime();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @param array<string, mixed> $attributes */
    private function queuedJob(array $attributes = []): int
    {
        return DB::table('jobs')->insertGetId(array_merge([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => ProcessVideo::class, 'data' => ['command' => 'private-serialized-data']], JSON_THROW_ON_ERROR),
            'attempts' => 0, 'reserved_at' => null,
            'available_at' => now()->timestamp, 'created_at' => now()->subMinute()->timestamp,
        ], $attributes));
    }

    private function failedJob(): int
    {
        return DB::table('failed_jobs')->insertGetId([
            'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['displayName' => LibraryInvitation::class, 'data' => ['command' => 'private-invitation-url']], JSON_THROW_ON_ERROR),
            'exception' => 'Symfony\\Component\\Mailer\\Exception\\TransportException: smtp-password-secret',
            'failed_at' => now(),
        ]);
    }

    public function test_only_active_administrators_can_access_the_queue_overview(): void
    {
        $this->queuedJob();
        $this->get('/admin/queue-overview')->assertRedirect();

        foreach ([[], ['is_contributor' => true], ['is_admin' => true, 'is_active' => false], ['is_admin' => true, 'invitation_token' => 'pending']] as $attributes) {
            $this->actingAs(User::factory()->create($attributes));
            $this->get('/admin/queue-overview')->assertForbidden();
            Livewire::test(QueueOverview::class)->assertForbidden();
        }

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->get('/admin/queue-overview')->assertOk()->assertSee('Warteschlange')->assertSee('Video prüfen');
        $this->get('/admin')->assertOk()->assertSee(route('filament.admin.pages.queue-overview'), false);
    }

    public function test_queue_states_and_both_worker_reservation_timeouts_are_displayed_correctly(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        config(['queue.connections.database.retry_after' => 300, 'queue.connections.transcoding.retry_after' => 3780]);
        $waiting = $this->queuedJob();
        $delayed = $this->queuedJob(['available_at' => now()->addMinute()->timestamp, 'attempts' => 1]);
        $reserved = $this->queuedJob(['reserved_at' => now()->subMinute()->timestamp, 'attempts' => 1]);
        $stale = $this->queuedJob(['reserved_at' => now()->subSeconds(300)->timestamp, 'attempts' => 2]);
        $conversion = $this->queuedJob(['queue' => 'transcoding', 'payload' => json_encode(['displayName' => OptimizeVideo::class]), 'reserved_at' => now()->subMinutes(20)->timestamp, 'attempts' => 1]);
        $staleConversion = $this->queuedJob(['queue' => 'transcoding', 'reserved_at' => now()->subSeconds(3780)->timestamp, 'attempts' => 1]);
        $standard = $this->queuedJob(['queue' => 'standard', 'payload' => json_encode(['displayName' => PrepareWebVideo::class]), 'reserved_at' => now()->subMinutes(20)->timestamp, 'attempts' => 1]);
        $this->failedJob();

        $component = Livewire::test(QueueOverview::class)
            ->assertSee('Standard-Version erstellen')->assertSee('Web-Version (Priorität)')->assertSee('Wartend')->assertSee('Geplant')->assertSee('Reserviert')->assertSee('Reservierung abgelaufen')
            ->assertSee('HD-MP4 erstellen')->assertSee('MP4-Konvertierung');
        $records = $component->instance()->records(1, 10)->getCollection()->keyBy('id');

        $this->assertSame('waiting', $records[$waiting]['status']);
        $this->assertSame('delayed', $records[$delayed]['status']);
        $this->assertSame('reserved', $records[$reserved]['status']);
        $this->assertSame('stale', $records[$stale]['status']);
        $this->assertSame('reserved', $records[$conversion]['status']);
        $this->assertSame('stale', $records[$staleConversion]['status']);
        $this->assertSame('reserved', $records[$standard]['status']);
        $this->assertSame(['pending' => 2, 'reserved' => 5, 'failed' => 1], $component->instance()->summary());
    }

    public function test_failed_jobs_have_readable_labels_without_exposing_payloads_or_exception_secrets(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->queuedJob();
        $id = $this->failedJob();

        $component = Livewire::test(QueueOverview::class)
            ->assertDontSee('private-serialized-data')
            ->callAction('failedJobs')
            ->assertSee('Einladungs-E-Mail versenden')->assertSee('E-Mail-Versand fehlgeschlagen')
            ->assertDontSee('private-invitation-url')->assertDontSee('smtp-password-secret');
        $record = $component->instance()->records(1, 10)->items()[0];
        $this->assertSame('failed-'.$id, $record['key']);
        $this->assertArrayNotHasKey('payload', $record);
        $this->assertArrayNotHasKey('exception', $record);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('failed_jobs', 1);
    }

    public function test_pagination_reaches_all_jobs_and_switching_lists_resets_the_page(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        for ($index = 0; $index < 12; $index++) {
            $this->queuedJob();
        }
        $this->failedJob();
        $component = Livewire::test(QueueOverview::class);
        $records = $component->instance()->records(2, 10);
        $this->assertSame(12, $records->total());
        $this->assertCount(2, $records->items());

        $component->set('paginators.page', 2)->callAction('failedJobs')->assertSee('E-Mail-Versand fehlgeschlagen');
        $component->callAction('activeJobs')->assertSee('Video prüfen');
        $this->assertFalse($component->instance()->showFailed);
    }

    public function test_refresh_reflects_jobs_being_added_and_completed_and_handles_unrecognized_payloads(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $component = Livewire::test(QueueOverview::class)->assertSee('Keine offenen Aufträge');
        $id = $this->queuedJob(['payload' => json_encode(['displayName' => DetectVideoRecordingDate::class])]);
        $component->callAction('refresh')->assertSee('Aufnahmedatum erkennen');
        DB::table('jobs')->where('id', $id)->delete();
        $component->callAction('refresh')->assertSee('Keine offenen Aufträge');

        $this->queuedJob(['payload' => 'invalid-json-with-private-data']);
        $component->callAction('refresh')->assertSee('Hintergrundauftrag')->assertDontSee('invalid-json-with-private-data');
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_livewire_refresh_cannot_read_the_queue_after_administrator_access_is_removed(): void
    {
        $administrator = User::factory()->create(['is_admin' => true]);
        $this->actingAs($administrator);
        $component = Livewire::test(QueueOverview::class);
        $administrator->forceFill(['is_admin' => false, 'is_contributor' => true])->save();

        $component->call('$refresh')->assertForbidden();
    }

    public function test_queue_links_both_current_and_legacy_jobs_to_the_video_with_playback_status(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        $video = Video::create([
            'uuid' => (string) Str::uuid(), 'title' => 'Ferien am See', 'original_filename' => 'ferien-2023.mp4',
            'mime_type' => 'video/mp4', 'file_size' => 4 * 1073741824, 'status' => VideoStatus::Ready,
            'storage_path' => 'originals/private', 'uploaded_by' => $admin->id,
            'web_status' => 'ready', 'web_path' => 'web/private.mp4', 'optimization_status' => 'processing',
        ]);
        $legacy = $this->queuedJob(['payload' => json_encode(['displayName' => OptimizeVideo::class, 'data' => ['command' => serialize(new OptimizeVideo($video->id, 'private-token'))]])]);
        $current = $this->queuedJob(['payload' => json_encode(['displayName' => PrepareWebVideo::class, 'homereel_video_id' => $video->id])]);
        $component = Livewire::test(QueueOverview::class)
            ->assertSee('Ferien am See')->assertSee('ferien-2023.mp4')->assertSee('4,00 GiB')
            ->assertSee('Standard bereit · HD wird vorbereitet')->assertSee(route('videos.show', $video), false)
            ->assertDontSee('private-token')->assertDontSee('originals/private');
        $records = $component->instance()->records(1, 10)->getCollection()->keyBy('id');
        foreach ([$legacy, $current] as $id) {
            $this->assertSame('Ferien am See', $records[$id]['video_title']);
            $this->assertSame(route('videos.show', $video), $records[$id]['video_url']);
        }
        $video->delete();
        $component->callAction('refresh')->assertSee('Ferien am See (gelöscht)');
        $record = $component->instance()->records(1, 10)->items()[0];
        $this->assertNull($record['video_url']);
        $this->assertSame('Video gelöscht', $record['playback']);
        $video->forceDelete();
        $component->callAction('refresh')->assertSee('Video #'.$video->id.' nicht mehr vorhanden');
    }

    public function test_failed_jobs_keep_their_video_reference(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $id = $this->failedJob();
        DB::table('failed_jobs')->where('id', $id)->update(['payload' => json_encode(['displayName' => PrepareWebVideo::class, 'homereel_video_id' => 12345])]);
        Livewire::test(QueueOverview::class)->callAction('failedJobs')->assertSee('Standard-Version erstellen')->assertSee('Video #12345 nicht mehr vorhanden');
    }

    public function test_invalid_or_unrecognized_payloads_do_not_resolve_arbitrary_objects_as_videos(): void
    {
        $reference = app(QueuedVideoReference::class);
        foreach ([
            'invalid-json',
            json_encode(['displayName' => ProcessVideo::class, 'homereel_video_id' => ['id' => 1]]),
            json_encode(['displayName' => ProcessVideo::class, 'data' => ['command' => 'O:8:"stdClass":1:{s:7:"videoId";i:1;}']]),
            json_encode(['displayName' => 'UnknownJob', 'homereel_video_id' => 1]),
            json_encode(['displayName' => ProcessVideo::class, 'homereel_video_id' => -1]),
        ] as $payload) {
            $this->assertNull($reference->fromPayload($payload));
        }
    }
}
