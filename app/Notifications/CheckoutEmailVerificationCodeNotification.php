<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CheckoutEmailVerificationCodeNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $code)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your booking email verification code')
            ->greeting('Hello')
            ->line('Use this code to connect your booking to your existing customer account:')
            ->line('Your code: '.$this->code)
            ->line('This code expires in 10 minutes. If you did not request it, you can ignore this email.');
    }
}
