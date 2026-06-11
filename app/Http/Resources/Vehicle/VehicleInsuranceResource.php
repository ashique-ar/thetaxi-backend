<?php
// app/Http/Resources/Vehicle/VehicleInsuranceResource.php

namespace App\Http\Resources\Vehicle;

use Illuminate\Http\Resources\Json\JsonResource;

class VehicleInsuranceResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicle_id,
            'provider_id' => $this->provider_id,
            'insurance_type_id' => $this->insurance_type_id,
            'policy_number' => $this->policy_number,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'premium_amount' => $this->premium_amount !== null ? (float) $this->premium_amount : null,
            'renewal_reminder_date' => $this->renewal_reminder_date,
            'renewal_date' => $this->renewal_date,
            'status' => $this->status,
            'renewed_from_id' => $this->renewed_from_id,
            'document_files' => $this->document_files ?? [],
            'remarks' => $this->remarks,
            'vehicle' => new VehicleResource($this->whenLoaded('vehicle')),
            'provider' => new VehicleInsuranceProviderResource($this->whenLoaded('provider')),
            'insurance_type' => new VehicleInsuranceTypeResource($this->whenLoaded('insuranceType')),
        ];
    }
}
