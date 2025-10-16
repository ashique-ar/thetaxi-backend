<?php
// app/Http/Resources/Vehicle/VehicleMaintenanceRecordResource.php

namespace App\Http\Resources\Vehicle;

use Illuminate\Http\Resources\Json\JsonResource;

class VehicleMaintenanceRecordResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'             => $this->id,
            'vehicle_id'     => $this->vehicle_id,
            'schedule_id'    => $this->schedule_id,
            'performed_date' => $this->performed_date,
            'cost'           => $this->cost,
            'notes'          => $this->notes,
            'created_at'     => $this->created_at,
            'updated_at'     => $this->updated_at,
        ];
    }
}
