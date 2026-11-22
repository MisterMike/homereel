<?php

return [
    'default' => 'local',
    'disks' => [
        'local' => ['driver' => 'local', 'root' => storage_path('app/private'), 'throw' => true, 'serve' => false],
        'videos' => ['driver' => 'local', 'root' => env('VIDEO_STORAGE_ROOT', storage_path('app/videos')), 'visibility' => 'private', 'throw' => true, 'serve' => false, 'permissions' => ['file' => ['public' => 0600, 'private' => 0600], 'dir' => ['public' => 0700, 'private' => 0700]]],
    ],
    'links' => [],
];
