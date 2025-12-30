<?php
// app/Http/Requests/Vehicle/VehicleAddon/UpdateVehicleAddonRequest.php

namespace App\Http\Requests\Vehicle\VehicleAddon;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleAddonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Basic info
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'thumbnail' => ['sometimes', 'nullable', 'string', 'max:500'],

            // Classification
            'service_type_id' => ['sometimes', 'nullable', 'exists:service_types,id'],
            'category_id' => ['sometimes', 'nullable', 'uuid'],
            'addon_type' => ['sometimes', 'required', 'in:service,item,insurance,fee,discount'],

            // Pricing configuration
            'pricing_type' => ['sometimes', 'required', 'in:fixed,per_day,per_hour,per_km,percentage,tiered'],
            'amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'base_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'rate_type' => ['sometimes', 'nullable', 'in:flat,percentage'],
            'billing_type' => ['sometimes', 'nullable', 'in:per_package,per_day,per_hour'],

            // Quantity configuration
            'quantity_unit' => ['sometimes', 'nullable', 'in:pieces,km,hours,days,passengers'],
            'min_qty' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'max_qty' => ['sometimes', 'nullable', 'integer', 'gte:min_qty'],
            'min_quantity' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'max_quantity' => ['sometimes', 'nullable', 'integer'],
            'allow_quantity_selection' => ['sometimes', 'nullable', 'boolean'],

            // Threshold pricing
            'threshold_quantity' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'threshold_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],

            // Tax configuration
            'is_taxable' => ['sometimes', 'nullable', 'boolean'],
            'tax_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],

            // Status and availability
            'is_active' => ['sometimes', 'nullable', 'boolean'],
            'is_mandatory' => ['sometimes', 'nullable', 'boolean'],
            'is_optional' => ['sometimes', 'nullable', 'boolean'],
            'availability_type' => ['sometimes', 'nullable', 'in:always,conditional,seasonal,service_specific'],
            'availability_conditions' => ['sometimes', 'nullable', 'array'],

            // Vehicle compatibility
            'compatible_vehicle_types' => ['sometimes', 'nullable', 'array'],
            'compatible_vehicle_types.*' => ['string'],

            // Validity period
            'valid_from' => ['sometimes', 'nullable', 'date'],
            'valid_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:valid_from'],
            'valid_until' => ['sometimes', 'nullable', 'date'],

            // Display settings
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:100'],
            'tags' => ['sometimes', 'nullable', 'array'],
            'tags.*' => ['string', 'max:50'],

            // Notes and integration
            'internal_notes' => ['sometimes', 'nullable', 'string'],
            'integration_settings' => ['sometimes', 'nullable', 'array'],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Map frontend aliases to backend field names
        $mappings = [
            'base_price' => 'amount',
            'min_quantity' => 'min_qty',
            'max_quantity' => 'max_qty',
            'valid_until' => 'valid_to',
            'tax_percentage' => 'tax_rate',
        ];

        foreach ($mappings as $alias => $actual) {
            if ($this->has($alias) && !$this->has($actual)) {
                $this->merge([$actual => $this->input($alias)]);
            }
        }
    }
}
