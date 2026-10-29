<?php

namespace App\Notifications;

use App\Models\Inquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

class RespondIoInquiryNeedsOwnerNotification extends Notification implements ShouldBroadcast, ShouldQueue
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
            'title' => 'Respond.io inquiry needs an employee',
            'description' => "Inquiry {$number} is waiting in the unassigned queue.",
            'type' => 'respond_io_inquiry_needs_owner',
            'link' => '/communication/inquiries?unassigned_only=true',
            'useRouter' => true,
            'data' => ['inquiry_id' => (string) $this->inquiry->id, 'inquiry_number' => $number],
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        $number = (string) ($this->inquiry->inquiry_number ?: $this->inquiry->id);

        return new BroadcastMessage([
            'title' => 'Respond.io inquiry needs an employee',
            'description' => "Inquiry {$number} is waiting in the unassigned queue.",
            'type' => 'respond_io_inquiry_needs_owner',
            'link' => '/communication/inquiries?unassigned_only=true',
            'useRouter' => true,
        ]);
    }
}
