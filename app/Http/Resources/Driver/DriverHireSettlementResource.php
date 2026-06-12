<?php

namespace App\Http\Resources\Driver;

use App\Http\Resources\Vehicle\VehicleGroupResource;
use App\Http\Resources\Vehicle\VehicleResource;
use Illuminate\Http\Resources\Json\JsonResource;

class DriverHireSettlementResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'booking_id' => $this->booking_id,
            'booking_item_id' => $this->booking_item_id,
            'driver_id' => $this->driver_id,
            'vehicle_id' => $this->vehicle_id,
            'vehicle_group_id' => $this->vehicle_group_id,
            'driver_log_id' => $this->driver_log_id,
            'batta_rule_id' => $this->batta_rule_id,
            'batta_category' => $this->batta_category,
            'base_batta_amount' => (float) $this->base_batta_amount,
            'night_count' => (int) $this->night_count,
            'night_batta_rate' => (float) $this->night_batta_rate,
            'night_batta_amount' => (float) $this->night_batta_amount,
            'manual_adjustment_amount' => (float) $this->manual_adjustment_amount,
            'manual_adjustment_reason' => $this->manual_adjustment_reason,
            'approved_batta_amount' => (float) $this->approved_batta_amount,
            'approved_expenses_total' => (float) $this->approved_expenses_total,
            'iou_total' => (float) $this->iou_total,
            'final_balance' => (float) $this->final_balance,
            'status' => $this->status,
            'submitted_at' => $this->submitted_at,
            'ops_reviewed_at' => $this->ops_reviewed_at,
            'ops_reviewed_by' => $this->ops_reviewed_by,
            'ops_review_notes' => $this->ops_review_notes,
            'accounts_finalized_at' => $this->accounts_finalized_at,
            'accounts_finalized_by' => $this->accounts_finalized_by,
            'accounts_notes' => $this->accounts_notes,
            'paid_at' => $this->paid_at,
            'recovered_at' => $this->recovered_at,
            'rejection_reason' => $this->rejection_reason,
            'booking' => $this->whenLoaded('booking'),
            'booking_item' => $this->whenLoaded('bookingItem'),
            'driver' => new DriverResource($this->whenLoaded('driver')),
            'vehicle' => new VehicleResource($this->whenLoaded('vehicle')),
            'vehicle_group' => new VehicleGroupResource($this->whenLoaded('vehicleGroup')),
            'driver_log' => new DriverLogResource($this->whenLoaded('driverLog')),
            'batta_rule' => new DriverBattaRuleResource($this->whenLoaded('battaRule')),
            'expenses' => DriverSettlementExpenseResource::collection($this->whenLoaded('expenses')),
            'iou_advances' => DriverIouAdvanceResource::collection($this->whenLoaded('iouAdvances')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
