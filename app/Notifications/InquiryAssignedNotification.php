<?php

namespace App\Notifications;

use App\Models\Inquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

class InquiryAssignedNotification extends Notification implements ShouldBroadcast, ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(private readonly Inquiry $inquiry)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        $number = (string) ($this->inquiry->inquiry_number ?: $this->inquiry->id);

        return [
            'title' => 'Inquiry assigned to you',
            'description' => "Inquiry {$number} needs your attention.",
            'type' => 'inquiry_assigned',
            'link' => '/communication/inquiries/'.$this->inquiry->id,
            'useRouter' => true,
            'data' => ['inquiry_id' => (string) $this->inquiry->id, 'inquiry_number' => $number],
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        $number = (string) ($this->inquiry->inquiry_number ?: $this->inquiry->id);

        return new BroadcastMessage([
            'title' => 'Inquiry assigned to you',
            'description' => "Inquiry {$number} needs your attention.",
            'type' => 'inquiry_assigned',
            'link' => '/communication/inquiries/'.$this->inquiry->id,
            'useRouter' => true,
        ]);
    }
}
