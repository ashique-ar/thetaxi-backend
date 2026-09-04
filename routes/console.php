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
Schedule::command('sales:process-tasks')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('sales:project-effective-changes')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('sales:expire-profile-exports')
    ->dailyAt('02:00')
    ->withoutOverlapping();

if (config('sales.features.performance_alert_evaluations')) {
    Schedule::command('sales:process-performance-alerts --commit')
        ->everyFifteenMinutes()
        ->withoutOverlapping();
}

Schedule::command('foundation:publish-outbox-events --commit')
    ->everyMinute()
    ->withoutOverlapping();

if (config('hr.features.leave_overtime') && config('hr.system_user_id')) {
    Schedule::command('hr:process-leave-accruals --commit')
        ->dailyAt('00:30')
        ->withoutOverlapping();
}
if (config('hr.features.employee_self_service') && config('hr.system_user_id')) {
    Schedule::command('hr:process-scheduled-exits --commit')->dailyAt('00:45')->withoutOverlapping();
}
if (config('hr.features.engagement_analytics') && config('hr.system_user_id')) {
    Schedule::command('hr:process-report-schedules --commit')->everyFifteenMinutes()->withoutOverlapping();
    Schedule::command('hr:expire-report-artifacts --commit')->dailyAt('02:15')->withoutOverlapping();
    Schedule::command('hr:process-notifications --commit')->everyMinute()->withoutOverlapping();
}

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
