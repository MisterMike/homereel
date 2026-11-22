<?php

return [
    'disk' => 'videos',
    'max_bytes' => (int) env('VIDEO_MAX_BYTES', 8589934592),
    'chunk_requests_per_minute' => (int) env('VIDEO_CHUNK_REQUESTS_PER_MINUTE', 1200),
    'chunk_bytes' => (int) env('VIDEO_CHUNK_BYTES', 8388608),
    'storage_limit_bytes' => (int) env('VIDEO_STORAGE_LIMIT_BYTES', 429496729600),
    'upload_hours' => (int) env('VIDEO_UPLOAD_HOURS', 48),
    'mime_types' => ['video/mp4', 'video/quicktime', 'video/webm', 'video/x-matroska'],
    'extensions' => ['mp4', 'm4v', 'mov', 'webm', 'mkv'],
    'ffprobe' => env('FFPROBE_PATH', 'ffprobe'),
    'ffmpeg' => env('FFMPEG_PATH', 'ffmpeg'),
    'thumbnails' => (bool) env('VIDEO_THUMBNAILS', false),
    'optimization_enabled' => (bool) env('VIDEO_OPTIMIZATION_ENABLED', false),
    'optimization_auto' => (bool) env('VIDEO_OPTIMIZATION_AUTO', false),
    'optimization_timeout' => (int) env('VIDEO_OPTIMIZATION_TIMEOUT', 3600),
    'process_timeout' => (int) env('VIDEO_PROCESS_TIMEOUT', 60),
];
