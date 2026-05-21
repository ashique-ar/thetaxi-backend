<?php

namespace App\Http\Resources\Vehicle;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleDiscountResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            
            // Scope information
            'service_type_id' => $this->service_type_id,
            'vehicle_group_id' => $this->vehicle_group_id,
            'scope_description' => $this->scope_description,
            'precedence_level' => $this->precedence_level,
            
            // Discount configuration
            'amount' => $this->amount,
            'is_percentage' => $this->is_percentage,
            'applies_to' => $this->applies_to,
            
            // Validity and status
            'valid_from' => $this->valid_from?->format('Y-m-d H:i:s'),
            'valid_to' => $this->valid_to?->format('Y-m-d H:i:s'),
            'is_active' => $this->is_active,
            'is_valid' => $this->is_valid,
            'is_available' => $this->is_available,
            
            // Additional configuration
            'minimum_amount' => $this->minimum_amount,
            'maximum_discount' => $this->maximum_discount,
            'usage_limit' => $this->usage_limit,
            'usage_count' => $this->usage_count,
            
            // Relationships
            'service_type' => $this->whenLoaded('serviceType', function () {
                return [
                    'id' => $this->serviceType->id,
                    'name' => $this->serviceType->name,
                    'code' => $this->serviceType->code ?? null,
                ];
            }),
            
            'vehicle_group' => $this->whenLoaded('vehicleGroup', function () {
                return [
                    'id' => $this->vehicleGroup->id,
                    'name' => $this->vehicleGroup->name,
                    'code' => $this->vehicleGroup->code ?? null,
                ];
            }),
            
            'created_by' => $this->whenLoaded('createdBy', function () {
                return [
                    'id' => $this->createdBy->id,
                    'name' => $this->createdBy->name,
                    'email' => $this->createdBy->email,
                ];
            }),
            
            'updated_by' => $this->whenLoaded('updatedBy', function () {
                return [
                    'id' => $this->updatedBy->id,
                    'name' => $this->updatedBy->name,
                    'email' => $this->updatedBy->email,
                ];
            }),
            
            // Computed fields
            'formatted_amount' => $this->is_percentage 
                ? $this->amount . '%' 
                : '$' . number_format(floor(max(0, $this->amount)), 0),
                
            'status_badge' => [
                'text' => $this->getStatusText(),
                'color' => $this->getStatusColor(),
                'icon' => $this->getStatusIcon(),
            ],
            
            'validity_status' => [
                'text' => $this->getValidityText(),
                'color' => $this->getValidityColor(),
                'is_valid' => $this->is_valid,
            ],
            
            'usage_status' => [
                'used' => $this->usage_count,
                'limit' => $this->usage_limit,
                'unlimited' => !$this->usage_limit,
                'percentage' => $this->usage_limit 
                    ? round(($this->usage_count / $this->usage_limit) * 100, 1)
                    : 0,
                'remaining' => $this->usage_limit 
                    ? max(0, $this->usage_limit - $this->usage_count)
                    : null,
            ],
            
            // Audit information
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Get status text for UI display
     */
    private function getStatusText(): string
    {
        if (!$this->is_active) return 'Inactive';
        if (!$this->is_valid) return 'Invalid';
        if (!$this->is_available) return 'Unavailable';
        return 'Active';
    }

    /**
     * Get status color for UI display
     */
    private function getStatusColor(): string
    {
        if (!$this->is_active) return 'gray';
        if (!$this->is_valid) return 'orange';
        if (!$this->is_available) return 'red';
        return 'green';
    }

    /**
     * Get status icon for UI display
     */
    private function getStatusIcon(): string
    {
        if (!$this->is_active) return 'pause';
        if (!$this->is_valid) return 'clock';
        if (!$this->is_available) return 'x-circle';
        return 'check-circle';
    }

    /**
     * Get validity text for UI display
     */
    private function getValidityText(): string
    {
        $now = now();
        
        if ($this->valid_from && $this->valid_from > $now) {
            return 'Starts ' . $this->valid_from->diffForHumans();
        }
        
        if ($this->valid_to && $this->valid_to < $now) {
            return 'Expired ' . $this->valid_to->diffForHumans();
        }
        
        if ($this->valid_to) {
            return 'Expires ' . $this->valid_to->diffForHumans();
        }
        
        return 'No expiry';
    }

    /**
     * Get validity color for UI display
     */
    private function getValidityColor(): string
    {
        $now = now();
        
        if ($this->valid_from && $this->valid_from > $now) {
            return 'blue'; // Future
        }
        
        if ($this->valid_to && $this->valid_to < $now) {
            return 'red'; // Expired
        }
        
        if ($this->valid_to && $this->valid_to->diffInDays($now) <= 7) {
            return 'orange'; // Expiring soon
        }
        
        return 'green'; // Valid
    }
}
