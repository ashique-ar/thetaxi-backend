<?php
// app/Http/Resources/InquiryResource.php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class InquiryResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'           => $this->id,
            'customer_id'  => $this->customer_id,
            'subject'      => $this->subject,
            'message'      => $this->message,
            'status'       => $this->status,
            'priority'     => $this->priority,
            'response'     => $this->response,
            'responded_at' => $this->responded_at,
            'created_at'   => $this->created_at,
            'updated_at'   => $this->updated_at,
        ];
    }
}
