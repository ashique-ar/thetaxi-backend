<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DriverPasswordResetNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $otp,
        private readonly int $expiresInMinutes
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Reset your ' . (\App\Models\BusinessSetting::getSetting('company_name') ?: \App\Models\Website\WebsiteSetting::getValue('company_name', config('app.name'))) . ' Driver password')
            ->greeting('Hello!')
            ->line('A password reset was requested for your driver account.')
            ->line('Your one-time password is: '.$this->otp)
            ->line('This OTP expires in '.$this->expiresInMinutes.' minutes and can only be used once.')
            ->line('If you did not request this change, you can ignore this email.');
    }

    public function toArray(object $notifiable): array
    {
        return ['action' => 'driver_password_reset'];
    }
}
