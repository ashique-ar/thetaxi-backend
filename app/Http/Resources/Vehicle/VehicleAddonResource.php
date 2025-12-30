<?php
// app/Http/Resources/Vehicle/VehicleAddonResource.php

namespace App\Http\Resources\Vehicle;

use App\Http\Resources\ServiceTypeResource;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleAddonResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'service_type_id' => $this->service_type_id,
            'category_id' => $this->category_id,
            'name' => $this->name,
            'addon_type' => $this->addon_type,
            'pricing_type' => $this->pricing_type,
            'quantity_unit' => $this->quantity_unit,
            'thumbnail' => $this->thumbnail,
            'min_qty' => $this->min_qty,
            'max_qty' => $this->max_qty,
            'min_quantity' => $this->min_qty, // Alias for frontend compatibility
            'max_quantity' => $this->max_qty, // Alias for frontend compatibility
            'allow_quantity_selection' => $this->allow_quantity_selection,
            'threshold_quantity' => $this->threshold_quantity,
            'threshold_price' => $this->threshold_price,
            'description' => $this->description,
            'amount' => $this->amount,
            'base_price' => $this->amount, // Alias for frontend compatibility
            'rate_type' => $this->rate_type,
            'billing_type' => $this->billing_type,
            'is_taxable' => $this->is_taxable,
            'tax_rate' => $this->tax_rate,
            'tax_percentage' => $this->tax_rate, // Alias for frontend compatibility
            'is_active' => $this->is_active,
            'is_mandatory' => $this->is_mandatory,
            'is_optional' => $this->is_optional,
            'availability_type' => $this->availability_type,
            'availability_conditions' => $this->availability_conditions,
            'compatible_vehicle_types' => $this->compatible_vehicle_types,
            'sort_order' => $this->sort_order,
            'icon' => $this->icon,
            'tags' => $this->tags,
            'internal_notes' => $this->internal_notes,
            'integration_settings' => $this->integration_settings,
            'valid_from' => $this->valid_from?->format('Y-m-d'),
            'valid_to' => $this->valid_to?->format('Y-m-d'),
            'valid_until' => $this->valid_to?->format('Y-m-d'), // Alias for frontend compatibility
            
            // Computed properties
            'is_valid' => $this->isValid(),
            
            // Relationships
            'service_type' => new ServiceTypeResource($this->whenLoaded('serviceType')),
            
            // Metadata
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'created_user_id' => $this->created_user_id,
            'updated_user_id' => $this->updated_user_id,
        ];
    }
}
