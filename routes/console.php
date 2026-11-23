<?php

use Illuminate\Support\Facades\Schedule;

// Clean up abandoned uploads every hour
Schedule::command('uploads:cleanup')
    ->hourly();

// Process queued video jobs
Schedule::command(
    'queue:work --stop-when-empty --tries=3 --timeout=60 --max-time=50'
)
    ->everyMinute()
    ->withoutOverlapping(10);