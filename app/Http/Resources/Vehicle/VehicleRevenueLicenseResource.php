<?php

namespace App\Http\Resources\Vehicle;

use Illuminate\Http\Resources\Json\JsonResource;

class VehicleRevenueLicenseResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->documentable_id,
            'license_number' => $this->document_number,
            'issued_date' => $this->metadata['issued_date'] ?? null,
            'expiry_date' => $this->expiry_date,
            'renewal_reminder_date' => $this->metadata['renewal_reminder_date'] ?? null,
            'renewal_date' => $this->metadata['renewal_date'] ?? null,
            'renewed_from_id' => $this->replaces_document_id,
            'authority_name' => $this->metadata['authority_name'] ?? null,
            'document_files' => $this->metadata['document_files'] ?? [],
            'status' => $this->status,
            'notes' => $this->metadata['notes'] ?? null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
