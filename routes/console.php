<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('uploads:cleanup')->hourly()->withoutOverlapping();
