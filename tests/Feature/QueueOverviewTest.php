<?php

namespace Tests\Feature;

use App\Filament\Pages\QueueOverview;
use App\Jobs\DetectVideoRecordingDate;
use App\Jobs\OptimizeVideo;
use App\Jobs\ProcessVideo;
use App\Models\User;
use App\Notifications\LibraryInvitation;
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
        $this->failedJob();

        $component = Livewire::test(QueueOverview::class)
            ->assertSee('Wartend')->assertSee('Geplant')->assertSee('Reserviert')->assertSee('Reservierung abgelaufen')
            ->assertSee('Optimierte MP4 erstellen')->assertSee('MP4-Konvertierung');
        $records = $component->instance()->records(1, 10)->getCollection()->keyBy('id');

        $this->assertSame('waiting', $records[$waiting]['status']);
        $this->assertSame('delayed', $records[$delayed]['status']);
        $this->assertSame('reserved', $records[$reserved]['status']);
        $this->assertSame('stale', $records[$stale]['status']);
        $this->assertSame('reserved', $records[$conversion]['status']);
        $this->assertSame('stale', $records[$staleConversion]['status']);
        $this->assertSame(['pending' => 2, 'reserved' => 4, 'failed' => 1], $component->instance()->summary());
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
}
