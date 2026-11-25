<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlaybackTest extends TestCase
{
    use RefreshDatabase;

    private Video $video;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('videos');
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->video = Video::create(['uuid' => (string) Str::uuid(), 'title' => 'Test', 'original_filename' => 'test.mp4', 'mime_type' => 'video/mp4', 'file_size' => 10, 'status' => VideoStatus::Ready, 'storage_path' => 'originals/test', 'storage_provider' => 'local', 'uploaded_by' => $user->id, 'prototype_available' => true, 'thumbnail_path' => 'thumbnails/test.jpg', 'web_status' => 'ready', 'web_path' => 'web/test.mp4']);
        Storage::disk('videos')->put('originals/test', 'PRIVATE ORIGINAL');
        Storage::disk('videos')->put('web/test.mp4', '0123456789');
        Storage::disk('videos')->put('thumbnails/test.jpg', 'jpeg');
        $this->url = '/videos/'.$this->video->uuid.'/stream';
    }

    public function test_get_head_and_private_thumbnail(): void
    {
        $response = $this->get($this->url)->assertOk()->assertHeader('Content-Length', '10')->assertHeader('Accept-Ranges', 'bytes');
        $this->assertSame('0123456789', $response->streamedContent());
        $this->head($this->url)->assertOk()->assertHeader('Content-Length', '10')->assertContent('');
        $this->get('/videos/'.$this->video->uuid.'/thumbnail')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_single_open_ended_suffix_and_clamped_ranges_return_exact_bytes(): void
    {
        foreach (['bytes=2-5' => ['2345', 'bytes 2-5/10'], 'bytes=7-' => ['789', 'bytes 7-9/10'], 'bytes=-3' => ['789', 'bytes 7-9/10'], 'bytes=8-99' => ['89', 'bytes 8-9/10']] as $range => $expected) {
            $response = $this->withHeader('Range', $range)->get($this->url)->assertStatus(206)->assertHeader('Content-Range', $expected[1])->assertHeader('Content-Length', (string) strlen($expected[0]));
            $this->assertSame($expected[0], $response->streamedContent());
        }
    }

    public function test_invalid_ranges_return_416_and_head_ignores_range(): void
    {
        foreach (['bytes=20-30', 'bytes=6-2', 'bytes=-0', 'bytes=', 'bytes=0-1,4-5', 'nonsense'] as $range) {
            $this->withHeader('Range', $range)->get($this->url)->assertStatus(416)->assertHeader('Content-Range', 'bytes */10');
        }
        $this->withHeader('Range', 'bytes=2-3')->head($this->url)->assertOk()->assertHeader('Content-Length', '10');
    }

    public function test_deleted_and_unavailable_videos_cannot_stream(): void
    {
        $this->video->update(['prototype_available' => false]);
        $this->get($this->url)->assertForbidden();
        $this->video->delete();
        $this->get($this->url)->assertNotFound();
    }

    public function test_optimized_streams_use_the_same_authorization_and_range_handling(): void
    {
        Storage::disk('videos')->put('optimized/test.mp4', 'OPTIMIZED');
        $this->video->update(['optimization_status' => 'ready', 'optimized_path' => 'optimized/test.mp4']);
        $this->assertSame('0123456789', $this->get($this->url)->assertOk()->assertHeader('Content-Type', 'video/mp4')->streamedContent());
        $this->get($this->url.'?quality=original')->assertUnprocessable();
        $this->assertSame('OPTIMIZED', $this->get($this->url.'?quality=hd')->assertOk()->streamedContent());
        $this->assertSame('TIM', $this->withHeader('Range', 'bytes=2-4')->get($this->url.'?quality=optimized')->assertStatus(206)->assertHeader('Content-Range', 'bytes 2-4/9')->streamedContent());
        $this->head($this->url.'?quality=optimized')->assertOk()->assertHeader('Content-Length', '9');
        $this->video->update(['prototype_available' => false]);
        $this->get($this->url.'?quality=optimized')->assertForbidden();
    }

    public function test_unpublished_and_unknown_variants_cannot_be_requested(): void
    {
        $this->get($this->url.'?quality=optimized')->assertNotFound();
        $this->get($this->url.'?quality=../../original')->assertUnprocessable();
        $this->get($this->url.'?quality[]=original')->assertUnprocessable();
    }

    public function test_sparse_five_gib_file_seeks_without_loading_original_into_memory(): void
    {
        $size = 5 * 1073741824;
        $file = fopen(Storage::disk('videos')->path('web/test.mp4'), 'c+b');
        ftruncate($file, $size);
        fseek($file, $size - 4);
        fwrite($file, 'TAIL');
        fclose($file);
        clearstatcache();
        $before = memory_get_usage(true);
        $response = $this->withHeader('Range', 'bytes='.($size - 4).'-')->get($this->url)->assertStatus(206)->assertHeader('Content-Length', '4')->assertHeader('Content-Range', 'bytes '.($size - 4).'-'.($size - 1).'/'.$size);
        $this->assertSame('TAIL', $response->streamedContent());
        $this->assertLessThan(8 * 1048576, memory_get_usage(true) - $before);
    }
}
