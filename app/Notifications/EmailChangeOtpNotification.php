<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailChangeOtpNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $otp)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verify Your New Email Address')
            ->greeting('Hello!')
            ->line('Enter this one-time code to update your account email:')
            ->line($this->otp)
            ->line('This code expires in 10 minutes and can only be used once.');
    }
}
