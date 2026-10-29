<?php

namespace App\Notifications;

use App\Models\PhoneCall;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

class PhoneCallAssignedNotification extends Notification implements ShouldBroadcast, ShouldQueue
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
        return $this->payload();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->payload());
    }

    private function payload(): array
    {
        if ($this->call->follow_up_due_at) {
            return [
                'title' => 'Callback scheduled for you',
                'description' => 'Call back '.($this->call->client_name ?: $this->call->phone ?: 'the customer').' by '.$this->call->follow_up_due_at->format('M j, Y').'.',
                'type' => 'phone_call_callback_scheduled',
                'link' => '/communication/calls/'.$this->call->id,
                'useRouter' => true,
                'data' => ['phone_call_id' => (string) $this->call->id],
            ];
        }

        return [
            'title' => 'Call assigned to you',
            'description' => 'A call from '.($this->call->client_name ?: $this->call->phone ?: 'a customer').' needs your attention.',
            'type' => 'phone_call_assigned',
            'link' => '/communication/calls/'.$this->call->id,
            'useRouter' => true,
            'data' => ['phone_call_id' => (string) $this->call->id],
        ];
    }
}
