<?php
// app/Http/Resources/Vehicle/VehicleMaintenanceScheduleResource.php

namespace App\Http\Resources\Vehicle;

use Illuminate\Http\Resources\Json\JsonResource;

class VehicleMaintenanceScheduleResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'            => $this->id,
            'vehicle_id'    => $this->vehicle_id,
            'type'          => $this->type,
            'interval_km'   => $this->interval_km,
            'interval_days' => $this->interval_days,
            'next_due_date' => $this->next_due_date,
            'status'        => $this->status,
            'notes'         => $this->notes,
            'booking_id'    => $this->booking_id,
            'booking_item_id' => $this->booking_item_id,
            'booking_qc_id' => $this->booking_qc_id,
            'booking_number' => $this->whenLoaded('booking', fn () => $this->booking?->booking_number),
            'created_at'    => $this->created_at,
            'updated_at'    => $this->updated_at,
        ];
    }
}
