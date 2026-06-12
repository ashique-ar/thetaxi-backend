<?php

namespace App\Http\Resources\Driver;

use Illuminate\Http\Resources\Json\JsonResource;

class DriverIouAdvanceResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'driver_hire_settlement_id' => $this->driver_hire_settlement_id,
            'booking_id' => $this->booking_id,
            'driver_id' => $this->driver_id,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'issued_date' => $this->issued_date,
            'reference_number' => $this->reference_number,
            'notes' => $this->notes,
            'issued_by' => $this->issued_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
