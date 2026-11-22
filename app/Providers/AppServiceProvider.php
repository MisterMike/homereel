<?php

namespace App\Providers;

use App\Contracts\ResumableUploads;
use App\Contracts\VideoDelivery;
use App\Services\Video\LocalDelivery;
use App\Services\Video\LocalUploads;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(VideoDelivery::class, LocalDelivery::class);
        $this->app->bind(ResumableUploads::class, LocalUploads::class);
    }

    public function boot(): void
    {
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(120)->by('uploads:'.$request->user()->id));
        RateLimiter::for('upload-chunks', fn (Request $request) => Limit::perMinute(config('video.chunk_requests_per_minute'))->by('chunks:'.$request->user()->id));
        RateLimiter::for('upload-creation', fn (Request $request) => Limit::perHour(30)->by('create:'.$request->user()->id));
    }
}
