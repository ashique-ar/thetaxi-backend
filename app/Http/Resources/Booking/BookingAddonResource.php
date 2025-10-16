<?php
// app/Http/Resources/BookingAddonResource.php

namespace App\Http\Resources\Booking;

use Illuminate\Http\Resources\Json\JsonResource;

class BookingAddonResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'booking_id' => $this->booking_id,
            'addon_id' => $this->addon_id,
            'qty' => $this->qty,
            'rate' => $this->rate,
            'amount' => $this->amount,
            'is_insurance' => $this->is_insurance,
            'is_milage' => $this->is_milage,
            'label' => $this->label,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
