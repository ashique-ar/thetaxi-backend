<?php

namespace App\Console\Commands;

use App\Models\Driver\Driver;
use App\Notifications\DriverLicenseExpiryNotification;
use App\Services\Driver\NotificationTriggerService;
use Illuminate\Console\Command;

class SendDriverLicenseReminders extends Command
{
    protected $signature = 'drivers:send-license-reminders';
    protected $description = 'Notify drivers whose driving licences are approaching expiry';

    public function handle(NotificationTriggerService $notifications): int
    {
        $count = 0;
        Driver::with('user')->whereNotNull('license_expiry')->whereNull('license_last_reminded_on')
            ->where('is_active', true)->chunkById(100, function ($drivers) use (&$count) {
                foreach ($drivers as $driver) {
                    if (!$driver->user || now()->startOfDay()->diffInDays($driver->license_expiry, false) > ($driver->license_reminder_days ?? 30)) continue;
                    $driver->user->notify(new DriverLicenseExpiryNotification($driver));
                    $daysRemaining = now()->startOfDay()->diffInDays($driver->license_expiry, false);
                    $notifications->sendDriverLicenseNotification(
                        $driver,
                        'driver_license_expiry',
                        $daysRemaining < 0 ? 'Driving licence expired' : 'Driving licence renewal reminder',
                        $daysRemaining < 0
                            ? 'Your driving licence has expired. Renew it and update your licence details.'
                            : "Your driving licence expires on {$driver->license_expiry->toDateString()}. Please renew it before expiry.",
                        ['days_remaining' => (string) $daysRemaining],
                        false
                    );
                    $driver->update(['license_last_reminded_on' => today()]);
                    $count++;
                }
            });

        $this->info("{$count} driver licence reminder(s) queued.");
        return self::SUCCESS;
    }
}
