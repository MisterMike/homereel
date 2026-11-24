<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use Tests\TestCase;

class QueueSchedulerTest extends TestCase
{
    private function schedule(): Schedule
    {
        $schedule = new Schedule;
        ScheduleFacade::swap($schedule);
        require base_path('routes/console.php');

        return $schedule;
    }

    public function test_default_and_conversion_queues_have_independent_background_workers(): void
    {
        config(['video.process_timeout' => 60, 'video.optimization_timeout' => 3600]);
        $events = collect($this->schedule()->events());
        $workers = $events->filter(fn (Event $event): bool => str_contains($event->command, 'queue:work'))->values();
        $this->assertCount(2, $workers);
        $normal = $workers->first(fn (Event $event): bool => str_contains($event->command, 'queue:work database '));
        $conversion = $workers->first(fn (Event $event): bool => str_contains($event->command, 'queue:work transcoding --queue=transcoding '));
        $this->assertNotNull($normal);
        $this->assertNotNull($conversion);
        $this->assertStringContainsString('--timeout=150', $normal->command);
        $this->assertStringContainsString('--tries=3', $normal->command);
        $this->assertStringContainsString('--timeout=3690', $conversion->command);
        $this->assertStringContainsString('--tries=1', $conversion->command);
        $this->assertNotSame($normal->mutexName(), $conversion->mutexName());

        foreach ($workers as $worker) {
            $this->assertSame('* * * * *', $worker->expression);
            $this->assertTrue($worker->runInBackground);
            $this->assertTrue($worker->withoutOverlapping);
            $this->assertStringContainsString('--stop-when-empty', $worker->command);
            $this->assertStringContainsString('--max-time=50', $worker->command);
        }
    }

    public function test_worker_timeouts_and_overlap_locks_follow_configured_processing_limits(): void
    {
        config(['video.process_timeout' => 120, 'video.optimization_timeout' => 7200]);
        $workers = collect($this->schedule()->events())->filter(fn (Event $event): bool => str_contains($event->command, 'queue:work'));
        $normal = $workers->first(fn (Event $event): bool => str_contains($event->command, 'queue:work database '));
        $conversion = $workers->first(fn (Event $event): bool => str_contains($event->command, 'queue:work transcoding '));

        $this->assertStringContainsString('--timeout=270', $normal->command);
        $this->assertStringContainsString('--timeout=7290', $conversion->command);
        $this->assertGreaterThan(270 + 50, $normal->expiresAt * 60);
        $this->assertGreaterThan(7290 + 50, $conversion->expiresAt * 60);
    }
}
