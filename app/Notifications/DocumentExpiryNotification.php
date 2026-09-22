<?php

namespace App\Notifications;

use App\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DocumentExpiryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Document $document) {}
    public function via(object $notifiable): array { return ['mail', 'database']; }
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Document expiry reminder')
            ->line(str($this->document->document_type)->replace('_', ' ')->title().' expires on '.$this->document->expiry_date->format('Y-m-d').'.')
            ->line('Please upload the renewed document before it expires.');
    }
    public function toArray(object $notifiable): array
    {
        return ['document_id' => $this->document->id, 'document_type' => $this->document->document_type,
            'expiry_date' => $this->document->expiry_date->toDateString(), 'action' => 'driver_onboarding_document_renewal'];
    }
}
