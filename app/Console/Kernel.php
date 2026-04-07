<?php

namespace App\Console;

use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Console\Scheduling\Schedule;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        // Generate sitemap daily and optionally ping search engines
        $schedule->command('sitemap:generate-and-ping')->daily();

        // Process auto-offline for inactive drivers every 5 minutes
        // @see Requirement 5.3 - Scheduled job every 5 minutes
        // $schedule->command('drivers:process-auto-offline')->everyFiveMinutes();

        // Clean up expired short URLs daily
        $schedule->command('short-urls:cleanup')->daily();

        // Drain queued background jobs on environments where a dedicated
        // long-running worker / supervisor is not configured.
        $schedule->command(sprintf(
            'queue:work %s --stop-when-empty --queue=%s --tries=%d --timeout=%d --sleep=%d --max-time=%d',
            config('queue.default', 'database'),
            $this->scheduledQueueList(),
            $this->scheduledQueueTries(),
            $this->scheduledQueueTimeout(),
            $this->scheduledQueueSleep(),
            $this->scheduledQueueMaxTime()
        ))
            ->everyMinute()
            ->withoutOverlapping(10)
            ->runInBackground();
    }

    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }

    private function scheduledQueueList(): string
    {
        return env('SCHEDULED_QUEUE_WORKER_QUEUES', 'default,sms,driver-notifications');
    }

    private function scheduledQueueTries(): int
    {
        return max(1, (int) env('SCHEDULED_QUEUE_WORKER_TRIES', 3));
    }

    private function scheduledQueueTimeout(): int
    {
        return max(30, (int) env('SCHEDULED_QUEUE_WORKER_TIMEOUT', 180));
    }

    private function scheduledQueueSleep(): int
    {
        return max(1, (int) env('SCHEDULED_QUEUE_WORKER_SLEEP', 3));
    }

    private function scheduledQueueMaxTime(): int
    {
        return max(30, (int) env('SCHEDULED_QUEUE_WORKER_MAX_TIME', 50));
    }
}
