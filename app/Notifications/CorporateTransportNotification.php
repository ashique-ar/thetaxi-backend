<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class CorporateTransportNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $message)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Staff transport update',
            'message' => $this->message,
            'type' => 'corporate_transport',
            'data' => ['url' => '/employee/staff-transport/calendar'],
        ];
    }
}
