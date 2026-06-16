<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sitemap:generate-and-ping')->daily();

// Schedule::command('drivers:process-auto-offline')->everyFiveMinutes();

Schedule::command('short-urls:cleanup')->daily();

Schedule::command('corporate-transport:generate-bookings')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10);

Schedule::command(sprintf(
    'queue:work %s --stop-when-empty --queue=%s --tries=%d --timeout=%d --sleep=%d --max-time=%d',
    config('queue.default', 'database'),
    env('SCHEDULED_QUEUE_WORKER_QUEUES', 'default,sms,driver-notifications'),
    max(1, (int) env('SCHEDULED_QUEUE_WORKER_TRIES', 3)),
    max(30, (int) env('SCHEDULED_QUEUE_WORKER_TIMEOUT', 180)),
    max(1, (int) env('SCHEDULED_QUEUE_WORKER_SLEEP', 3)),
    max(30, (int) env('SCHEDULED_QUEUE_WORKER_MAX_TIME', 50))
))
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground();
