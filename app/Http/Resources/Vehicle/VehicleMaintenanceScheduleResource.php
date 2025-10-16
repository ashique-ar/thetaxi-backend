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
            'created_at'    => $this->created_at,
            'updated_at'    => $this->updated_at,
        ];
    }
}
