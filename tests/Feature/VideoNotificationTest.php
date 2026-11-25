<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Jobs\OptimizeVideo;
use App\Jobs\PrepareWebVideo;
use App\Models\User;
use App\Models\Video;
use App\Notifications\VideoReady;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class VideoNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function video(): Video
    {
        return Video::create([
            'uuid' => (string) Str::uuid(), 'title' => 'Sommerferien', 'original_filename' => 'ferien.mp4',
            'mime_type' => 'video/mp4', 'file_size' => 4 * 1073741824, 'status' => VideoStatus::Ready,
            'storage_path' => 'originals/private', 'storage_provider' => 'local',
            'uploaded_by' => User::factory()->create(['is_contributor' => true])->id,
            'web_status' => 'ready', 'web_path' => 'web/private.mp4', 'web_height' => 720,
            'optimization_status' => 'processing',
        ]);
    }

    public function test_queued_notification_links_use_the_uuid_and_open_in_an_existing_session(): void
    {
        $this->withoutVite();
        $video = $this->video();
        $uploader = User::find($video->uploaded_by);
        $job = unserialize(serialize(new SendQueuedNotifications($uploader, new VideoReady($video), ['mail'])));
        $mail = $job->notification->toMail($uploader);
        $this->assertSame(route('videos.show', ['video' => $video->uuid]), $mail->actionUrl);
        $this->assertSame('Dein Video ist in Standardqualität bereit', $mail->subject);
        $this->assertStringContainsString('HD wird noch', implode(' ', $mail->introLines));
        $this->actingAs($uploader)->get($mail->actionUrl)->assertOk()->assertSee('Sommerferien');
        $this->get($mail->actionUrl)->assertOk();
    }

    public function test_guest_can_login_and_return_to_the_queued_email_video_without_a_404(): void
    {
        $this->withoutVite();
        $video = $this->video();
        $uploader = User::find($video->uploaded_by);
        $mail = unserialize(serialize(new VideoReady($video)))->toMail($uploader);
        $this->get($mail->actionUrl)->assertRedirect(route('login'));
        $this->post('/login', ['email' => $uploader->email, 'password' => 'password'])->assertRedirect($mail->actionUrl);
        $this->get($mail->actionUrl)->assertOk()->assertSee('Sommerferien');
    }

    public function test_real_queue_payloads_have_video_references_for_both_renditions_and_mail(): void
    {
        $video = $this->video();
        $uploader = User::find($video->uploaded_by);
        $jobs = [new PrepareWebVideo($video->id, 'token'), new OptimizeVideo($video->id, 'token'), new SendQueuedNotifications($uploader, new VideoReady($video), ['mail'])];
        foreach ($jobs as $job) {
            $id = Queue::connection('transcoding')->push($job, '', 'standard');
            $payload = json_decode(DB::table('jobs')->where('id', $id)->value('payload'), true, 32, JSON_THROW_ON_ERROR);
            $this->assertSame($video->id, $payload['homereel_video_id']);
        }
    }
}
