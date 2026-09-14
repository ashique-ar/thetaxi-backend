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

if (config('sms.retention.enabled')) {
    Schedule::command('sms:apply-retention --execute')
        ->dailyAt('02:30')
        ->withoutOverlapping();
}

Schedule::command('bookings:generate-recurring')
    ->dailyAt('01:00')
    ->withoutOverlapping();

Schedule::command('maintenance:check-scheduled')
    ->dailyAt('06:00')
    ->withoutOverlapping();

Schedule::command('bookings:process-payment-schedules')
    ->dailyAt('07:00')
    ->withoutOverlapping();

Schedule::command('corporate:deliver-management-reports')->dailyAt('06:00')->withoutOverlapping();

Schedule::call(fn () => app(\App\Services\FinancialAccountSettlementService::class)->markOverdueSettlements())
    ->dailyAt('00:05')
    ->name('mark-overdue-account-settlements')
    ->withoutOverlapping();

Schedule::command('vehicles:process-lease-schedules')
    ->dailyAt('07:15')
    ->withoutOverlapping();

Schedule::command('corporate-transport:generate-bookings')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10);

// Closes out booking items whose driver has already completed the trip
// (driver_assignments.trip_phase = completed) but whose booking item never
// got marked completed — otherwise these keep showing as "active" hires
// indefinitely in the ongoing-hire-management operations queue.
Schedule::command('bookings:reconcile-driver-completions')
    ->everyTwoMinutes()
    ->withoutOverlapping(5);

// Schedule::command('bookings:retry-final-pricing')
//     ->everyFifteenMinutes()
//     ->withoutOverlapping(10);

if (config('queue.scheduled_worker.enabled')) {
    Schedule::command(sprintf(
        'queue:work %s --stop-when-empty --queue=%s --tries=%d --timeout=%d --sleep=%d --max-time=%d',
        config('queue.default', 'database'),
        config('queue.scheduled_worker.queues'),
        config('queue.scheduled_worker.tries'),
        config('queue.scheduled_worker.timeout'),
        config('queue.scheduled_worker.sleep'),
        config('queue.scheduled_worker.max_time'),
    ))
        ->everyMinute()
        ->withoutOverlapping(10)
        ->runInBackground();
}
