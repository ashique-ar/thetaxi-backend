<?php
// app/Http/Resources/NotificationLogResource.php

namespace App\Http\Resources\NotificationLog;

use Illuminate\Http\Resources\Json\JsonResource;

class NotificationLogResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'template_id' => $this->template_id,
            'content' => $this->content,
            'channel' => $this->channel,
            'type' => $this->channel,
            'subject' => $this->template?->subject,
            'template_code' => $this->template?->code,
            'recipient_name' => trim(($this->user?->first_name ?? '') . ' ' . ($this->user?->last_name ?? ''))
                ?: $this->user?->email
                ?: 'System recipient',
            'recipient_contact' => $this->user?->email,
            'sent_at' => $this->sent_at,
            'status' => $this->status,
            'delivery_status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
