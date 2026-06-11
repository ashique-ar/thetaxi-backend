<?php

namespace App\Http\Resources\Vehicle;

use Illuminate\Http\Resources\Json\JsonResource;

class VehicleRevenueLicenseResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicle_id,
            'license_number' => $this->license_number,
            'issued_date' => $this->issued_date,
            'expiry_date' => $this->expiry_date,
            'renewal_reminder_date' => $this->renewal_reminder_date,
            'renewal_date' => $this->renewal_date,
            'renewed_from_id' => $this->renewed_from_id,
            'authority_name' => $this->authority_name,
            'document_files' => $this->document_files ?? [],
            'status' => $this->status,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
