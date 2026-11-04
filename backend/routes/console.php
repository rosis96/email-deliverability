<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Hourly: read-only Instantly sync -> map accounts to batches -> pull daily
// sending analytics -> run problem detection. Requires the scheduler process
// (`php artisan schedule:work`), which deploy/entrypoint.sh starts.
Schedule::command('deliverability:refresh')->hourly()->withoutOverlapping(55);
