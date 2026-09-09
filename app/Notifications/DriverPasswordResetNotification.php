<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DriverPasswordResetNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = 'thetaxidriver://reset-password?token='.rawurlencode($this->token)
            .'&email='.rawurlencode($notifiable->getEmailForPasswordReset());

        return (new MailMessage)
            ->subject('Reset your TheTaxi Driver password')
            ->greeting('Hello!')
            ->line('A password reset was requested for your driver account.')
            ->action('Reset Driver Password', $url)
            ->line('This link expires in '.config('auth.passwords.users.expire').' minutes and can only be used once.')
            ->line('If you did not request this change, you can ignore this email.');
    }

    public function toArray(object $notifiable): array
    {
        return ['action' => 'driver_password_reset'];
    }
}
