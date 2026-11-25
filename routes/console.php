<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('uploads:cleanup')
    ->hourly()
    ->withoutOverlapping();

$processingTimeout = 2 * (int) config('video.process_timeout') + 30;
$conversionTimeout = (int) config('video.optimization_timeout') + 90;

Schedule::command('queue:work database --stop-when-empty --tries=3 --timeout='.$processingTimeout.' --max-time=50')
    ->everyMinute()
    ->withoutOverlapping((int) ceil(($processingTimeout + 50) / 60) + 1)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/queue.log'));

Schedule::command('queue:work transcoding --queue=standard,transcoding --stop-when-empty --tries=1 --timeout='.$conversionTimeout.' --max-time=50')
    ->everyMinute()
    ->withoutOverlapping((int) ceil(($conversionTimeout + 50) / 60) + 1)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/transcoding.log'));
