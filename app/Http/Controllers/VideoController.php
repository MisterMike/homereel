<?php

namespace App\Http\Controllers;

use App\Contracts\VideoDelivery;
use App\Models\Video;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class VideoController extends Controller
{
    public function index(Request $request): View
    {
        return view('videos.index', ['videos' => Video::visibleTo($request->user())->latest()->paginate(24)]);
    }

    public function show(Video $video): View
    {
        Gate::authorize('view', $video);

        return view('videos.show', compact('video'));
    }

    public function stream(Video $video, Request $request, VideoDelivery $delivery): Response
    {
        Gate::authorize('view', $video);

        return $delivery->stream($video, $request);
    }

    public function thumbnail(Video $video, Request $request, VideoDelivery $delivery): Response
    {
        Gate::authorize('view', $video);

        return $delivery->thumbnail($video, $request);
    }
}
