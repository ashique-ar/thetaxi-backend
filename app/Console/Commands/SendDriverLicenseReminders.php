<?php

namespace App\Console\Commands;

use App\Models\Driver\Driver;
use App\Notifications\DriverLicenseExpiryNotification;
use Illuminate\Console\Command;

class SendDriverLicenseReminders extends Command
{
    protected $signature = 'drivers:send-license-reminders';
    protected $description = 'Notify drivers whose driving licences are approaching expiry';

    public function handle(): int
    {
        $count = 0;
        Driver::with('user')->whereNotNull('license_expiry')->whereNull('license_last_reminded_on')
            ->where('is_active', true)->chunkById(100, function ($drivers) use (&$count) {
                foreach ($drivers as $driver) {
                    if (!$driver->user || now()->startOfDay()->diffInDays($driver->license_expiry, false) > ($driver->license_reminder_days ?? 30)) continue;
                    $driver->user->notify(new DriverLicenseExpiryNotification($driver));
                    $driver->update(['license_last_reminded_on' => today()]);
                    $count++;
                }
            });

        $this->info("{$count} driver licence reminder(s) queued.");
        return self::SUCCESS;
    }
}
