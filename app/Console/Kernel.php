<?php

namespace App\Console;

use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;

class Kernel extends ConsoleKernel
{
    protected function schedule(\Illuminate\Console\Scheduling\Schedule $schedule): void
    {
        // Generate sitemap daily and optionally ping search engines
        $schedule->command('sitemap:generate-and-ping')->daily();

        // Process auto-offline for inactive drivers every 5 minutes
        // @see Requirement 5.3 - Scheduled job every 5 minutes
        $schedule->command('drivers:process-auto-offline')->everyFiveMinutes();

        // Clean up expired short URLs daily
        $schedule->command('short-urls:cleanup')->daily();
    }

    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
