<?php

namespace App\Http\Resources\Driver;

use Illuminate\Http\Resources\Json\JsonResource;

class DriverSettlementExpenseResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'driver_hire_settlement_id' => $this->driver_hire_settlement_id,
            'expense_type' => $this->expense_type,
            'claimed_amount' => (float) $this->claimed_amount,
            'approved_amount' => $this->approved_amount !== null ? (float) $this->approved_amount : null,
            'currency' => $this->currency,
            'vendor' => $this->vendor,
            'description' => $this->description,
            'receipt_files' => $this->receipt_files ?? [],
            'expense_date' => $this->expense_date,
            'status' => $this->status,
            'review_reason' => $this->review_reason,
            'reviewed_by' => $this->reviewed_by,
            'reviewed_at' => $this->reviewed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
