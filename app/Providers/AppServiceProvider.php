<?php

namespace App\Providers;

use App\Contracts\ResumableUploads;
use App\Contracts\VideoDelivery;
use App\Models\Album;
use App\Models\Video;
use App\Services\Video\LocalDelivery;
use App\Services\Video\LocalUploads;
use App\Services\Video\QueuedVideoReference;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View as BladeView;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(VideoDelivery::class, LocalDelivery::class);
        $this->app->bind(ResumableUploads::class, LocalUploads::class);
    }

    public function boot(): void
    {
        Queue::createPayloadUsing(function (?string $connection, ?string $queue, array $payload): array {
            $videoId = app(QueuedVideoReference::class)->fromJob($payload['data']['command'] ?? null);

            return $videoId === null ? [] : ['homereel_video_id' => $videoId];
        });
        View::composer('components.layout', function (BladeView $view): void {
            $user = auth()->user();
            if (! $user) {
                return;
            }
            $visibleVideos = fn (Builder $query): Builder => $query->visibleTo($user);
            $view->with([
                'navigationAlbums' => Album::whereHas('videos', $visibleVideos)
                    ->withCount(['videos as visible_videos_count' => $visibleVideos])->orderBy('name')->get(),
                'navigationVideoCount' => Video::visibleTo($user)->count(),
                'activeAlbumId' => request()->routeIs('videos.index') ? (int) request()->query('album') : null,
                'librarySearch' => is_string(request()->query('q')) ? request()->query('q') : '',
            ]);
        });
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(120)->by('uploads:'.$request->user()->id));
        RateLimiter::for('upload-chunks', fn (Request $request) => Limit::perMinute(config('video.chunk_requests_per_minute'))->by('chunks:'.$request->user()->id));
        RateLimiter::for('upload-creation', fn (Request $request) => Limit::perHour(30)->by('create:'.$request->user()->id));
    }
}
