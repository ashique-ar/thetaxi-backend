<?php

namespace App\Notifications;

use App\Models\PhoneCall;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

class PhoneCallNeedsOwnerNotification extends Notification implements ShouldBroadcast, ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(private readonly PhoneCall $call)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Call needs an employee',
            'description' => 'A new 3CX call is waiting in the unassigned call queue.',
            'type' => 'phone_call_needs_owner',
            'link' => '/communication/calls?unassigned_only=true',
            'useRouter' => true,
            'data' => ['phone_call_id' => (string) $this->call->id],
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'title' => 'Call needs an employee',
            'description' => 'A new 3CX call is waiting in the unassigned call queue.',
            'type' => 'phone_call_needs_owner',
            'link' => '/communication/calls?unassigned_only=true',
            'useRouter' => true,
        ]);
    }
}
