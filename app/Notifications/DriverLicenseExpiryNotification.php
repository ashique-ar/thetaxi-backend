<?php

namespace App\Notifications;

use App\Models\Driver\Driver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DriverLicenseExpiryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private Driver $driver) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Driving licence renewal reminder')
            ->line("Driving licence {$this->driver->license_no} expires on {$this->driver->license_expiry->toDateString()}.")
            ->line('Please renew it before accepting further assignments.');
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'driver_license_expiry', 'driver_id' => $this->driver->id, 'license_expiry' => $this->driver->license_expiry->toDateString()];
    }
}
