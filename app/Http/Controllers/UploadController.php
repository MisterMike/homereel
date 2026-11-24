<?php

namespace App\Http\Controllers;

use App\Contracts\ResumableUploads;
use App\Models\UploadSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UploadController extends Controller
{
    public function create(Request $request, ResumableUploads $uploads): JsonResponse
    {
        $data = $request->validate([
            'filename' => ['required', 'string', 'max:255', 'not_regex:/[\\\\\/\x00-\x1f]/'],
            'title' => ['required', 'string', 'max:255'], 'size' => ['required', 'integer', 'min:1', 'max:'.config('video.max_bytes')],
            'mime_type' => ['required', Rule::in(config('video.mime_types'))],
        ]);
        abort_unless(in_array(strtolower(pathinfo($data['filename'], PATHINFO_EXTENSION)), config('video.extensions'), true), 422, 'Nicht unterstützte Dateiendung.');

        return response()->json($this->payload($uploads->create($request->user(), $data)), 201);
    }

    public function show(UploadSession $upload): JsonResponse
    {
        Gate::authorize('view', $upload);

        return response()->json($this->payload($upload));
    }

    public function append(UploadSession $upload, Request $request, ResumableUploads $uploads): JsonResponse
    {
        Gate::authorize('update', $upload);
        abort_unless($request->header('Content-Type') === 'application/octet-stream', 415);
        $data = validator([
            'offset' => $request->header('Upload-Offset'), 'length' => $request->header('Content-Length'), 'checksum' => $request->header('Upload-Checksum'),
        ], ['offset' => ['required', 'integer', 'min:0'], 'length' => ['required', 'integer', 'min:1', 'max:'.$upload->chunk_bytes], 'checksum' => ['required', 'regex:/^[a-fA-F0-9]{64}$/D']])->validate();
        $input = $request->getContent(true);
        try {
            $result = $uploads->append($upload, (int) $data['offset'], (int) $data['length'], $data['checksum'], $input);
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
        }

        return response()->json($this->payload($result));
    }

    public function finish(UploadSession $upload, ResumableUploads $uploads): JsonResponse
    {
        Gate::authorize('update', $upload);
        $video = $uploads->finish($upload);

        return response()->json(['video_url' => route('videos.show', $video), 'status' => $video->status->value]);
    }

    private function payload(UploadSession $upload): array
    {
        return ['id' => $upload->id, 'filename' => $upload->original_filename, 'size' => $upload->expected_size, 'offset' => $upload->offset, 'chunk_bytes' => $upload->chunk_bytes, 'status' => $upload->status->value, 'expires_at' => $upload->expires_at->toIso8601String()];
    }
}
