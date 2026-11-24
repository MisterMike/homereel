<?php

namespace App\Http\Controllers;

use App\Contracts\VideoDelivery;
use App\Enums\VideoStatus;
use App\Models\Album;
use App\Models\Video;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class VideoController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate(['album' => ['nullable', 'integer', 'exists:albums,id'], 'q' => ['nullable', 'string', 'max:255']]);
        $album = isset($data['album']) ? Album::whereHas('videos', fn ($query) => $query->visibleTo($request->user()))->findOrFail($data['album']) : null;
        $search = trim($data['q'] ?? '');
        $videos = Video::visibleTo($request->user())->with('album')
            ->when($album, fn (Builder $query): Builder => $query->where('album_id', $album->id))
            ->when($search !== '', fn (Builder $query): Builder => $query->where(function (Builder $query) use ($search): void {
                $query->whereLike('title', '%'.$search.'%')->orWhereLike('description', '%'.$search.'%');
            }))->latest()->paginate(24)->withQueryString();

        return view('videos.index', compact('videos', 'album', 'search'));
    }

    public function show(Video $video): View
    {
        Gate::authorize('view', $video);

        $video->load('album');

        return view('videos.show', compact('video'));
    }

    public function stream(Video $video, Request $request, VideoDelivery $delivery): Response
    {
        Gate::authorize('view', $video);

        return $delivery->stream($video, $request);
    }

    public function recordView(Video $video, Request $request): JsonResponse
    {
        Gate::authorize('view', $video);
        abort_unless($video->status === VideoStatus::Ready, 409, 'Das Video ist noch nicht bereit.');

        DB::transaction(function () use ($video, $request): void {
            $recorded = DB::table('video_views')->insertOrIgnore([
                'video_id' => $video->id,
                'session_hash' => hash('sha256', $request->user()->id.':'.$request->session()->getId()),
            ]);

            if ($recorded) {
                $video->increment('view_count');
            }
        });

        return response()->json(['view_count' => $video->fresh()->view_count]);
    }

    public function thumbnail(Video $video, Request $request, VideoDelivery $delivery): Response
    {
        Gate::authorize('view', $video);

        return $delivery->thumbnail($video, $request);
    }
}
