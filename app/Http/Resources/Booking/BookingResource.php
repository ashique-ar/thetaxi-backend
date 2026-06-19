<?php
// app/Http/Resources/BookingResource.php

namespace App\Http\Resources\Booking;

use App\Http\Resources\Booking\BookingAddonResource;
use App\Http\Resources\Booking\BookingStatusResource;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'                => $this->id,
            'customer_id'       => $this->customer_id,
            'invoice_number'    => $this->invoice_number,
            'log_code'          => $this->log_code,
            'service_type_id'   => $this->service_type_id,
            'vip_id'            => $this->vip_id,
            'booking_date'      => $this->booking_date,
            'start_date'        => $this->start_date,
            'end_date'          => $this->end_date,
            'start_time'        => $this->start_time,
            'end_time'          => $this->end_time,
            'pickup_location'   => $this->pickup_location,
            'dropoff_location'  => $this->dropoff_location,
            'total_estimated'   => $this->total_estimated,
            'total_actual'      => $this->total_actual,
            'status'            => $this->status,
            'created_from'      => $this->created_from,
            'confirmed'         => $this->confirmed,
            'third_party_ref'   => $this->third_party_ref,
            'payment_status'    => $this->payment_status,
            'payment_responsibility' => $this->payment_responsibility,
            'payment_collection_method' => $this->payment_collection_method,
            'payment_collection_status' => $this->payment_collection_status,
            'payment_collected_amount' => $this->payment_collected_amount !== null ? (float) $this->payment_collected_amount : null,
            'payment_collected_at' => $this->payment_collected_at?->toISOString(),
            'payment_ref'       => $this->payment_ref,
            'created_at'        => $this->created_at,
            'updated_at'        => $this->updated_at,
            'addons'            => BookingAddonResource::collection($this->whenLoaded('addons')),
            'statuses'          => BookingStatusResource::collection($this->whenLoaded('statuses')),
        ];
    }
}
