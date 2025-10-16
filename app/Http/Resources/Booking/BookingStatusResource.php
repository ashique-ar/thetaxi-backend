<?php
// app/Http/Resources/BookingStatusResource.php

namespace App\Http\Resources\Booking;

use Illuminate\Http\Resources\Json\JsonResource;

class BookingStatusResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'          => $this->id,
            'booking_id'  => $this->booking_id,
            'old_status'  => $this->old_status,
            'new_status'  => $this->new_status,
            'created_at'  => $this->created_at,
        ];
    }
}
